#!/usr/bin/env php
<?php
/**
 * Mintsoft Order Sync
 *
 * Pulls orders from Mintsoft, fixes ExternalOrderReference using Ikonic API,
 * and queues orders for serial fetching.
 *
 * Run via cron, e.g.: * * * * * /usr/bin/php /path/to/bin/sync_orders.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\Database;
use MintsoftSync\ErrorLogger;
use MintsoftSync\IkonicClient;
use MintsoftSync\MintsoftClient;
use MintsoftSync\OrderMapper;
use MintsoftSync\Queue;
use MintsoftSync\RateLimiter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Predis\Client as RedisClient;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Setup local logger
$localLogger = new Logger('mintsoft-sync');
$localLogger->pushHandler(new StreamHandler(__DIR__ . '/../logs/sync.log', Logger::DEBUG));
$localLogger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Setup error logger (pushes to your dashboard)
$errorLogger = new ErrorLogger($config['error_hub'], $localLogger);

// ============================================
// Email notification settings
// ============================================
$notificationEmail = 'support@lamafulfilment.com';

/**
 * Send an email notification for external reference issues via SMTP.
 */
function sendExternalRefNotification(
    string $subject,
    string $body,
    string $toEmail,
    array $mailConfig,
    ErrorLogger $errorLogger
): void {
    try {
        $mail = new PHPMailer(true);

        // SMTP settings
        $mail->isSMTP();
        $mail->Host = $mailConfig['host'];
        $mail->Port = (int) $mailConfig['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailConfig['username'];
        $mail->Password = $mailConfig['password'];

        // Encryption
        if ($mailConfig['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($mailConfig['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        // From/To
        $mail->setFrom($mailConfig['from_address'], $mailConfig['from_name']);
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();

    } catch (PHPMailerException $e) {
        $errorLogger->warning('Failed to send notification email', [
            'to' => $toEmail,
            'subject' => $subject,
            'error' => $e->getMessage(),
        ]);
    } catch (\Throwable $e) {
        $errorLogger->warning('Exception sending notification email', [
            'to' => $toEmail,
            'subject' => $subject,
            'error' => $e->getMessage(),
        ]);
    }
}

// Setup Redis
$redisOptions = ['prefix' => $config['redis']['prefix']];
if (!empty($config['redis']['password'])) {
    $redisOptions['password'] = $config['redis']['password'];
}
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => $config['redis']['host'],
    'port' => $config['redis']['port'],
], $redisOptions);

// Initialise components
$rateLimiter = new RateLimiter(
    $redis,
    'ratelimit:mintsoft',
    $config['rate_limit']['requests'],
    $config['rate_limit']['per_seconds']
);

$mintsoftClient = new MintsoftClient($config['mintsoft'], $rateLimiter, $errorLogger);
$ikonicClient = new IkonicClient($config['ikonic']);
$database = new Database($config['database']);
$queue = new Queue($redis, '');
$mapper = new OrderMapper();

// ============================================
// Start sync
// ============================================
$localLogger->info('========================================');
$localLogger->info('Mintsoft Order Sync Started', [
    'client_id' => $config['mintsoft']['client_id'],
    'timestamp' => date('Y-m-d H:i:s'),
]);

try {
    // Determine the lookback period
    $lastSync = $database->getLastSyncTime('orders');

    if ($lastSync) {
        $modifiedSince = new DateTime($lastSync);
        $modifiedSince->modify('-5 minutes');
    } else {
        $modifiedSince = new DateTime();
        $modifiedSince->modify("-{$config['sync']['lookback_minutes']} minutes");
    }

    $localLogger->info('Fetching orders modified since', [
        'modified_since' => $modifiedSince->format('Y-m-d H:i:s'),
    ]);

    // Fetch orders from Mintsoft
    $orders = $mintsoftClient->getOrders($modifiedSince);

    if (empty($orders)) {
        $localLogger->info('No orders to process');
        $database->recordSyncRun('orders', 0);
        $localLogger->info('Sync complete - no orders');
        $localLogger->info('========================================');
        exit(0);
    }

    $localLogger->info('Fetched orders from Mintsoft', ['count' => count($orders)]);

    $processedCount = 0;
    $queuedForSerials = 0;
    $externalRefFixed = 0;
    $externalRefErrors = 0;

    foreach ($orders as $mintsoftOrder) {
        $mintsoftId = $mintsoftOrder['ID'] ?? $mintsoftOrder['Id'] ?? null;
        $orderNumber = $mintsoftOrder['OrderNumber'] ?? 'unknown';
        $currentExternalRef = $mintsoftOrder['ExternalOrderReference'] ?? null;

        try {
            $localLogger->debug('Processing order', [
                'mintsoft_id' => $mintsoftId,
                'order_number' => $orderNumber,
                'external_ref' => $currentExternalRef,
            ]);

            // Map and upsert the order
            $orderData = $mapper->mapOrder($mintsoftOrder);

            if (empty($orderData['mintsoft_id'])) {
                $localLogger->warning('Order missing ID, skipping', [
                    'order_number' => $orderNumber,
                ]);
                continue;
            }

            // ============================================
            // Fix ExternalOrderReference via Ikonic API
            // ============================================
            if (!empty($currentExternalRef)) {
                $localLogger->debug('Looking up correct external ref from Ikonic', [
                    'mintsoft_id' => $mintsoftId,
                    'current_external_ref' => $currentExternalRef,
                ]);

                $ikonicResult = $ikonicClient->getExternalOrderId($currentExternalRef);

                if ($ikonicResult['success']) {
                    $correctExternalRef = $ikonicResult['external_order_id'];

                    // Check if we need to update
                    if ($correctExternalRef !== $currentExternalRef) {
                        $localLogger->info('External reference mismatch - updating Mintsoft', [
                            'mintsoft_id' => $mintsoftId,
                            'order_number' => $orderNumber,
                            'current' => $currentExternalRef,
                            'correct' => $correctExternalRef,
                        ]);

                        // Update Mintsoft
                        $updateResult = $mintsoftClient->updateExternalOrderReference(
                            (int) $mintsoftId,
                            $correctExternalRef
                        );

                        if (isset($updateResult['Success']) && $updateResult['Success'] === true) {
                            $localLogger->info('Successfully updated ExternalOrderReference', [
                                'mintsoft_id' => $mintsoftId,
                                'order_number' => $orderNumber,
                                'old_value' => $currentExternalRef,
                                'new_value' => $correctExternalRef,
                            ]);

                            // Update our local data to use the correct value
                            $orderData['external_order_reference'] = $correctExternalRef;
                            $externalRefFixed++;

                        } else {
                            // Update failed - log error and send email
                            /*$errorMessage = $updateResult['Message'] ?? 'Unknown error';

                            $errorLogger->warning('Failed to update ExternalOrderReference in Mintsoft', [
                                'mintsoft_id' => $mintsoftId,
                                'order_number' => $orderNumber,
                                'current_external_ref' => $currentExternalRef,
                                'correct_external_ref' => $correctExternalRef,
                                'error' => $errorMessage,
                                'full_response' => $updateResult,
                            ]);

                            // Send notification email
                            $emailSubject = "Mintsoft External Ref Update Failed - Order {$orderNumber}";
                            $emailBody = "Failed to update ExternalOrderReference in Mintsoft.\n\n"
                                . "ORDER DETAILS:\n"
                                . "  Mintsoft ID:              {$mintsoftId}\n"
                                . "  Order Number:             {$orderNumber}\n"
                                . "  Current External Ref:     {$currentExternalRef}\n"
                                . "  Correct External Ref:     {$correctExternalRef}\n\n"
                                . "ERROR:\n"
                                . "  {$errorMessage}\n\n"
                                . "MANUAL ACTION REQUIRED:\n"
                                . "  Please update the External Order Reference in Mintsoft manually.\n"
                                . "  The correct value should be: {$correctExternalRef}\n\n"
                                . "Timestamp: " . date('Y-m-d H:i:s') . "\n";

                            sendExternalRefNotification(
                                $emailSubject,
                                $emailBody,
                                $notificationEmail,
                                $config['mail'],
                                $errorLogger
                            );

                            $externalRefErrors++;*/
                        }
                    } else {
                        $localLogger->debug('External reference already correct', [
                            'mintsoft_id' => $mintsoftId,
                            'external_ref' => $currentExternalRef,
                        ]);
                    }

                } else {
                    // Ikonic lookup failed - log warning and send email
                    $errorLogger->warning('Could not find order in Ikonic API', [
                        'mintsoft_id' => $mintsoftId,
                        'order_number' => $orderNumber,
                        'external_ref' => $currentExternalRef,
                        'ikonic_error' => $ikonicResult['error'],
                    ]);

                    // Send notification email
                    $emailSubject = "Ikonic Lookup Failed - Order {$orderNumber}";
                    $emailBody = "Could not find order in Ikonic API to get correct external reference.\n\n"
                        . "ORDER DETAILS:\n"
                        . "  Mintsoft ID:              {$mintsoftId}\n"
                        . "  Order Number:             {$orderNumber}\n"
                        . "  Current External Ref:     {$currentExternalRef}\n\n"
                        . "IKONIC ERROR:\n"
                        . "  " . ($ikonicResult['error'] ?? 'Unknown error') . "\n\n"
                        . "MANUAL ACTION REQUIRED:\n"
                        . "  Please verify the External Order Reference is correct in Mintsoft.\n"
                        . "  If incorrect, update it manually.\n\n"
                        . "Timestamp: " . date('Y-m-d H:i:s') . "\n";

                    sendExternalRefNotification(
                        $emailSubject,
                        $emailBody,
                        $notificationEmail,
                        $config['mail'],
                        $errorLogger
                    );

                    $externalRefErrors++;
                }
            }

            // Upsert the order to local database
            $orderId = $database->upsertOrder($orderData);
            $localLogger->debug('Upserted order to database', [
                'mintsoft_id' => $orderData['mintsoft_id'],
                'local_id' => $orderId,
            ]);

            // Process order items if present
            if (!empty($mintsoftOrder['OrderItems']) || !empty($mintsoftOrder['Items'])) {
                $items = $mintsoftOrder['OrderItems'] ?? $mintsoftOrder['Items'];
                foreach ($items as $item) {
                    $lineData = $mapper->mapOrderLine($item);
                    if (!empty($lineData['mintsoft_line_id'])) {
                        $database->upsertOrderLine($orderId, $lineData);
                    }
                }
            }

            // Queue for serial fetching
            $queue->push(Queue::QUEUE_SERIALS, [
                'mintsoft_order_id' => $orderData['mintsoft_id'],
                'local_order_id' => $orderId,
                'order_number' => $orderData['order_number'],
                'external_order_reference' => $orderData['external_order_reference'] ?? $orderData['order_number'],
            ]);
            $queuedForSerials++;

            $processedCount++;

        } catch (\Throwable $e) {
            $errorLogger->logException($e, [
                'context' => 'order_processing',
                'mintsoft_id' => $mintsoftId,
                'order_number' => $orderNumber,
            ]);
        }
    }

    $localLogger->info('Order sync completed', [
        'processed' => $processedCount,
        'queued_for_serials' => $queuedForSerials,
        'external_refs_fixed' => $externalRefFixed,
        'external_ref_errors' => $externalRefErrors,
    ]);

    // Record successful sync
    $database->recordSyncRun('orders', $processedCount);

} catch (\Throwable $e) {
    $errorLogger->logException($e, [
        'context' => 'order_sync_main',
    ]);

    $database->recordSyncRun('orders', 0, $e->getMessage());

    $localLogger->info('Sync failed with error');
    $localLogger->info('========================================');
    exit(1);
}

$localLogger->info('Sync complete', [
    'timestamp' => date('Y-m-d H:i:s'),
]);
$localLogger->info('========================================');
exit(0);
