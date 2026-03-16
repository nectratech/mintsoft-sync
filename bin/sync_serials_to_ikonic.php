#!/usr/bin/env php
<?php
/**
 * Sync Serials to Ikonic
 *
 * Pushes serial numbers and tracking information to Ikonic 3PL API.
 * Only syncs serials where the order has a tracking number.
 *
 * Run via cron, e.g.: * * * * * /usr/bin/php /path/to/bin/sync_serials_to_ikonic.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\Database;
use MintsoftSync\ErrorLogger;
use MintsoftSync\IkonicClient;
use MintsoftSync\RateLimiter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Setup local logger
$localLogger = new Logger('ikonic-sync');
$localLogger->pushHandler(new StreamHandler(__DIR__ . '/../logs/ikonic_sync.log', Logger::DEBUG));
$localLogger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Setup error logger (pushes to your dashboard)
$errorLogger = new ErrorLogger($config['error_hub'], $localLogger);

// Setup Redis for rate limiting
$redisOptions = ['prefix' => $config['redis']['prefix']];
if (!empty($config['redis']['password'])) {
    $redisOptions['password'] = $config['redis']['password'];
}
$redis = new RedisClient([
    'scheme' => 'tcp',
    'host' => $config['redis']['host'],
    'port' => $config['redis']['port'],
], $redisOptions);

// Initialise rate limiter for Ikonic API
$rateLimiter = new RateLimiter(
    $redis,
    'ratelimit:ikonic',
    $config['rate_limit']['requests'],
    $config['rate_limit']['per_seconds']
);

// Initialise components
$ikonicClient = new IkonicClient($config['ikonic'], $rateLimiter);
$database = new Database($config['database']);

// ============================================
// Start sync
// ============================================
$localLogger->info('========================================');
$localLogger->info('Ikonic Serial Sync Started', [
    'timestamp' => date('Y-m-d H:i:s'),
]);

try {
    // Get serials that need syncing
    $serials = $database->getUnsyncedSerials(100);

    if (empty($serials)) {
        $localLogger->info('No serials to sync');
        $database->recordSyncRun('ikonic_serials', 0);
        $localLogger->info('Sync complete - no serials');
        $localLogger->info('========================================');
        exit(0);
    }

    $localLogger->info('Found serials to sync', ['count' => count($serials)]);

    $syncedCount = 0;
    $failedCount = 0;
    $skippedCount = 0;
    $retryCount = 0;

    foreach ($serials as $serial) {
        $serialId = (int) $serial['serial_id'];
        $serialNumber = $serial['serial_number'];
        $sku = $serial['sku'];
        $orderId = $serial['order_id'];
        $externalOrderRef = $serial['external_order_reference'];
        $trackingNumber = $serial['tracking_number'];
        $orderNumber = $serial['order_number'];

        try {
            // Validate required fields
            if (empty($serialNumber)) {
                $localLogger->debug('Skipping serial - no serial number', [
                    'serial_id' => $serialId,
                    'order_id' => $orderId,
                ]);
                $skippedCount++;
                continue;
            }

            if (empty($sku)) {
                $localLogger->debug('Skipping serial - no SKU', [
                    'serial_id' => $serialId,
                    'serial_number' => $serialNumber,
                    'order_id' => $orderId,
                ]);
                $skippedCount++;
                continue;
            }

            // Build payload for Ikonic API
            // OrderID uses order_number with # prefix
            // ReferenceNumber uses external_order_reference without # prefix
            $payload = [
                'OrderID' => '#' . $orderNumber,
                'TrackingNumber' => $trackingNumber,
                'SerialNumber' => $serialNumber,
                'SKU' => $sku,
                'ReferenceNumber' => $externalOrderRef,
            ];

            $localLogger->debug('Pushing serial to Ikonic', [
                'serial_id' => $serialId,
                'payload' => $payload,
            ]);

            // Call Ikonic API
            $result = $ikonicClient->updateOrder($payload);

            if ($result['success']) {
                // Mark as synced
                $database->markSerialSyncedToIkonic($serialId);
                $syncedCount++;

                $localLogger->info('Serial synced to Ikonic', [
                    'serial_id' => $serialId,
                    'serial_number' => $serialNumber,
                    'order_id' => $externalOrderRef,
                ]);

            } else {
                // Mark as failed
                $errorMessage = $result['error'] ?? 'Unknown error';
                $database->markSerialIkonicError($serialId, $errorMessage);
                $failedCount++;

                $errorLogger->warning('Failed to sync serial to Ikonic', [
                    'serial_id' => $serialId,
                    'serial_number' => $serialNumber,
                    'order_id' => $externalOrderRef,
                    'sku' => $sku,
                    'error' => $errorMessage,
                    'response' => $result['response'] ?? null,
                ]);
            }

        } catch (\Throwable $e) {
            // Unexpected error - mark as failed
            $errorMessage = 'Exception: ' . $e->getMessage();
            $database->markSerialIkonicError($serialId, $errorMessage);
            $failedCount++;

            $errorLogger->logException($e, [
                'context' => 'ikonic_serial_sync',
                'serial_id' => $serialId,
                'serial_number' => $serialNumber ?? 'unknown',
            ]);
        }
    }

    // Log summary
    $localLogger->info('Ikonic serial sync completed', [
        'synced' => $syncedCount,
        'failed' => $failedCount,
        'skipped' => $skippedCount,
        'total_processed' => count($serials),
    ]);

    // Record sync run
    $database->recordSyncRun('ikonic_serials', $syncedCount, $failedCount > 0 ? "{$failedCount} failed" : null);

} catch (\Throwable $e) {
    $errorLogger->logException($e, [
        'context' => 'ikonic_sync_main',
    ]);

    $database->recordSyncRun('ikonic_serials', 0, $e->getMessage());

    $localLogger->info('Sync failed with error');
    $localLogger->info('========================================');
    exit(1);
}

$localLogger->info('Sync complete', [
    'timestamp' => date('Y-m-d H:i:s'),
]);
$localLogger->info('========================================');
exit(0);
