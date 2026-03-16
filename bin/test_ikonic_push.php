#!/usr/bin/env php
<?php
/**
 * Test Ikonic Push
 *
 * Tests the Ikonic API POST /3pl/update-order endpoint with sample data.
 * Does NOT touch the database - purely tests API connectivity.
 *
 * Usage:
 *   php bin/test_ikonic_push.php           # Run all test orders
 *   php bin/test_ikonic_push.php --dry-run # Show what would be sent without calling API
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\IkonicClient;
use MintsoftSync\RateLimiter;
use Predis\Client as RedisClient;

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

if ($help) {
    echo <<<HELP
Test Ikonic Push - Tests the Ikonic API connection

Usage:
  php bin/test_ikonic_push.php [options]

Options:
  --dry-run, -n   Show what would be sent without calling the API
  --help, -h      Show this help message

This script uses hardcoded test data to verify the Ikonic API connection.
It does NOT touch the database.

HELP;
    exit(0);
}

// Load config
$config = require __DIR__ . '/../config/config.php';

echo "=== Test Ikonic Push ===\n\n";

if ($dryRun) {
    echo "[DRY RUN MODE - No API calls will be made]\n\n";
}

// Test orders - these are sample payloads to test the API
// Note: OrderID and ReferenceNumber should have # prefix
$testOrders = [
    [
        'OrderID' => '#156558',
        'TrackingNumber' => '2026031605698760007706',
        'SerialNumber' => '0M1Z3HDXA00021',
        'SKU' => 'UE50DU8500KXXU',
        'ReferenceNumber' => '10-14359-36737',
    ]
];

echo "Configuration:\n";
echo "  API URL: {$config['ikonic']['api_url']}\n";
echo "  API Key: " . substr($config['ikonic']['api_key'], 0, 8) . "...\n\n";

echo "Test Orders:\n";
echo str_repeat('-', 80) . "\n";

foreach ($testOrders as $index => $order) {
    $num = $index + 1;
    echo "\n[{$num}/" . count($testOrders) . "] Testing order:\n";
    echo "  OrderID:         {$order['OrderID']}\n";
    echo "  TrackingNumber:  {$order['TrackingNumber']}\n";
    echo "  SerialNumber:    {$order['SerialNumber']}\n";
    echo "  SKU:             {$order['SKU']}\n";
    echo "  ReferenceNumber: {$order['ReferenceNumber']}\n";
}

echo "\n" . str_repeat('-', 80) . "\n\n";

if ($dryRun) {
    echo "Dry run complete. Use without --dry-run to actually call the API.\n";
    exit(0);
}

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

// Initialise rate limiter
$rateLimiter = new RateLimiter(
    $redis,
    'ratelimit:ikonic',
    $config['rate_limit']['requests'],
    $config['rate_limit']['per_seconds']
);

// Initialise Ikonic client
$ikonicClient = new IkonicClient($config['ikonic'], $rateLimiter);

echo "Executing API calls...\n\n";

$successCount = 0;
$failCount = 0;

foreach ($testOrders as $index => $order) {
    $num = $index + 1;
    echo "[{$num}/" . count($testOrders) . "] POST /3pl/update-order\n";
    echo "    Payload: " . json_encode($order) . "\n";

    try {
        $result = $ikonicClient->updateOrder($order);

        if ($result['success']) {
            echo "    ✓ SUCCESS";
            if (!empty($result['response'])) {
                echo " - Response: " . json_encode($result['response']);
            }
            echo "\n";
            $successCount++;
        } else {
            echo "    ✗ FAILED - {$result['error']}\n";
            if (!empty($result['response'])) {
                echo "    Response: " . json_encode($result['response']) . "\n";
            }
            $failCount++;
        }
    } catch (\Throwable $e) {
        echo "    ✗ EXCEPTION - {$e->getMessage()}\n";
        $failCount++;
    }

    echo "\n";
}

echo str_repeat('-', 80) . "\n";
echo "Summary:\n";
echo "  Total:    " . count($testOrders) . "\n";
echo "  Success:  {$successCount}\n";
echo "  Failed:   {$failCount}\n";
echo str_repeat('-', 80) . "\n";

exit($failCount > 0 ? 1 : 0);
