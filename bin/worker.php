#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Serial Fetch Worker
 * 
 * Processes the queue of orders that need their serials fetched.
 * Run via Supervisor to keep it running continuously.
 * 
 * Example supervisor config:
 * 
 * [program:mintsoft-worker]
 * process_name=%(program_name)s_%(process_num)02d
 * command=/usr/bin/php /path/to/mintsoft-sync/bin/worker.php
 * autostart=true
 * autorestart=true
 * user=www-data
 * numprocs=1
 * redirect_stderr=true
 * stdout_logfile=/var/log/mintsoft-worker.log
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\Database;
use MintsoftSync\ErrorLogger;
use MintsoftSync\MintsoftClient;
use MintsoftSync\OrderMapper;
use MintsoftSync\Queue;
use MintsoftSync\RateLimiter;
use MintsoftSync\SerialValidator;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Setup local logger
$localLogger = new Logger('mintsoft-worker');
$localLogger->pushHandler(new StreamHandler(__DIR__ . '/../logs/worker.log', Logger::DEBUG));
$localLogger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Setup error logger
$errorLogger = new ErrorLogger($config['error_hub'], $localLogger);

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
$database = new Database($config['database']);
$queue = new Queue($redis, '');
$mapper = new OrderMapper();
$serialValidator = new SerialValidator($config['database'], $errorLogger);

$localLogger->info('Worker started, waiting for jobs...');

// Max retries before moving to failed queue
const MAX_RETRIES = 3;

// Graceful shutdown handling
$shutdown = false;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$shutdown, $localLogger) {
    $localLogger->info('Received SIGTERM, shutting down gracefully...');
    $shutdown = true;
});
pcntl_signal(SIGINT, function () use (&$shutdown, $localLogger) {
    $localLogger->info('Received SIGINT, shutting down gracefully...');
    $shutdown = true;
});

// Main worker loop
while (!$shutdown) {
    try {
        // Pop a job from the queue (blocking for 5 seconds)
        $job = $queue->pop(Queue::QUEUE_SERIALS, 5);

        if ($job === null) {
            // No job available, continue waiting
            continue;
        }

        $payload = $job['payload'];
        $mintsoftOrderId = $payload['mintsoft_order_id'];
        $localOrderId = $payload['local_order_id'];
        $orderNumber = $payload['order_number'] ?? 'unknown';
        $externalOrderRef = $payload['external_order_reference'] ?? $orderNumber;

        $localLogger->info('Processing order for serials', [
            'mintsoft_order_id' => $mintsoftOrderId,
            'local_order_id' => $localOrderId,
            'order_number' => $orderNumber,
            'attempt' => $job['attempts'],
        ]);

        // Ensure database connection is alive (prevents "MySQL server has gone away")
        $database->ensureConnected();

        try {
            // First, fetch order items if we don't have them
            $orderItems = $mintsoftClient->getOrderItems($mintsoftOrderId);
            
            // Build a map of SKU/ProductId to line IDs
            $lineMap = [];
            foreach ($orderItems as $item) {
                $lineData = $mapper->mapOrderLine($item);
                if (!empty($lineData['mintsoft_line_id'])) {
                    $lineId = $database->upsertOrderLine($localOrderId, $lineData);
                    
                    // Map by both SKU and ProductId for lookup
                    if (!empty($lineData['sku'])) {
                        $lineMap['sku:' . $lineData['sku']] = $lineId;
                    }
                    if (!empty($lineData['product_id'])) {
                        $lineMap['product:' . $lineData['product_id']] = $lineId;
                    }
                    if (!empty($lineData['mintsoft_line_id'])) {
                        $lineMap['line:' . $lineData['mintsoft_line_id']] = $lineId;
                    }
                }
            }

            $localLogger->debug('Fetched order items', [
                'mintsoft_order_id' => $mintsoftOrderId,
                'item_count' => count($orderItems),
            ]);

            // Now fetch the barcode verified items (serials)
            $verifiedItems = $mintsoftClient->getBarcodeVerifiedOrderItems($mintsoftOrderId);

            $serialCount = 0;
            $validationErrors = 0;
            foreach ($verifiedItems as $verifiedItem) {
                $serialData = $mapper->mapSerial($verifiedItem);

                // Skip if no serial number
                if (empty($serialData['serial_number'])) {
                    continue;
                }

                // Validate serial against VL database
                $validation = $serialValidator->validateSerial(
                    $serialData['serial_number'],
                    $serialData['sku'] ?? '',
                    $externalOrderRef,
                    $orderNumber
                );

                if (!$validation['valid']) {
                    $localLogger->warning('Serial validation failed', [
                        'serial' => $serialData['serial_number'],
                        'sku' => $serialData['sku'] ?? '',
                        'order' => $orderNumber,
                        'error' => $validation['error'],
                    ]);
                    $validationErrors++;
                    // Continue processing - still insert to Mintsoft tables for tracking
                }

                // Try to find the matching order line
                $orderLineId = null;
                if (!empty($serialData['sku'])) {
                    $orderLineId = $lineMap['sku:' . $serialData['sku']] ?? null;
                }
                if (!$orderLineId && !empty($serialData['product_id'])) {
                    $orderLineId = $lineMap['product:' . $serialData['product_id']] ?? null;
                }
                if (!$orderLineId && !empty($verifiedItem['OrderItemId'])) {
                    $orderLineId = $lineMap['line:' . $verifiedItem['OrderItemId']] ?? null;
                }

                // Insert the serial
                $database->insertSerial($localOrderId, $orderLineId ?? 0, $serialData);
                $serialCount++;
            }

            $localLogger->info('Processed serials for order', [
                'mintsoft_order_id' => $mintsoftOrderId,
                'serial_count' => $serialCount,
                'validation_errors' => $validationErrors,
            ]);

            // Record successful serial sync
            $database->recordSyncRun('serials', $serialCount);

        } catch (\Throwable $e) {
            $errorLogger->logException($e, [
                'context' => 'serial_fetch',
                'mintsoft_order_id' => $mintsoftOrderId,
                'local_order_id' => $localOrderId,
                'attempt' => $job['attempts'],
            ]);

            // Check if we should retry
            if ($job['attempts'] < MAX_RETRIES) {
                // Exponential backoff: 30s, 60s, 120s
                $delay = 30 * pow(2, $job['attempts'] - 1);
                $localLogger->warning('Job failed, will retry', [
                    'mintsoft_order_id' => $mintsoftOrderId,
                    'attempt' => $job['attempts'],
                    'delay_seconds' => $delay,
                ]);
                
                // Re-queue for retry (simple approach - immediate re-queue)
                // In production you might want delayed retry
                sleep(min($delay, 60)); // Cap at 60s to not block too long
                $queue->push(Queue::QUEUE_SERIALS, $payload);
            } else {
                // Move to failed queue
                $queue->fail(Queue::QUEUE_SERIALS, $job, $e->getMessage());
                $localLogger->error('Job moved to failed queue after max retries', [
                    'mintsoft_order_id' => $mintsoftOrderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

    } catch (\Throwable $e) {
        // Outer catch for queue errors
        $errorLogger->logException($e, [
            'context' => 'worker_main_loop',
        ]);
        
        // Sleep a bit to avoid tight error loop
        sleep(5);
    }
}

$localLogger->info('Worker shutdown complete');
exit(0);