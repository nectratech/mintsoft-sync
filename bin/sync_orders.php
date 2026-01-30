#!/usr/bin/env php
<?php

declare(strict_types=1);


require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\Database;
use MintsoftSync\ErrorLogger;
use MintsoftSync\MintsoftClient;
use MintsoftSync\OrderMapper;
use MintsoftSync\Queue;
use MintsoftSync\RateLimiter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Setup local logger
$localLogger = new Logger('mintsoft-sync');
$localLogger->pushHandler(new StreamHandler(__DIR__ . '/../logs/sync.log', Logger::DEBUG));
$localLogger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Setup error logger (pushes to your dashboard)
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
$queue = new Queue($redis, ''); // Prefix already in Redis client
$mapper = new OrderMapper();

$localLogger->info('Starting order sync', [
    'client_id' => $config['mintsoft']['client_id'],
]);

try {
    // Determine the lookback period
    $lastSync = $database->getLastSyncTime('orders');
    
    if ($lastSync) {
        $modifiedSince = new DateTime($lastSync);
        // Subtract a small buffer to avoid missing orders
        $modifiedSince->modify('-5 minutes');
    } else {
        // First run - look back configured minutes
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
        exit(0);
    }

    $localLogger->info('Fetched orders from Mintsoft', ['count' => count($orders)]);

    $processedCount = 0;
    $queuedForSerials = 0;

    foreach ($orders as $mintsoftOrder) {
        try {
            // Map and upsert the order
            $orderData = $mapper->mapOrder($mintsoftOrder);
            
            if (empty($orderData['mintsoft_id'])) {
                $localLogger->warning('Order missing ID, skipping', ['order' => $mintsoftOrder]);
                continue;
            }

            $orderId = $database->upsertOrder($orderData);
            $localLogger->debug('Upserted order', [
                'mintsoft_id' => $orderData['mintsoft_id'],
                'local_id' => $orderId,
            ]);

            // Process order items if present in the response
            if (!empty($mintsoftOrder['OrderItems']) || !empty($mintsoftOrder['Items'])) {
                $items = $mintsoftOrder['OrderItems'] ?? $mintsoftOrder['Items'];
                foreach ($items as $item) {
                    $lineData = $mapper->mapOrderLine($item);
                    if (!empty($lineData['mintsoft_line_id'])) {
                        $database->upsertOrderLine($orderId, $lineData);
                    }
                }
            }

            // Queue this order for serial fetching
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
                'mintsoft_order' => $mintsoftOrder['ID'] ?? $mintsoftOrder['Id'] ?? 'unknown',
            ]);
        }
    }

    $localLogger->info('Order sync completed', [
        'processed' => $processedCount,
        'queued_for_serials' => $queuedForSerials,
    ]);

    // Record successful sync
    $database->recordSyncRun('orders', $processedCount);

} catch (\Throwable $e) {
    $errorLogger->logException($e, [
        'context' => 'order_sync_main',
    ]);
    
    $database->recordSyncRun('orders', 0, $e->getMessage());
    
    exit(1);
}

$localLogger->info('Sync complete');
exit(0);