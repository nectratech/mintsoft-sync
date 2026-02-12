#!/usr/bin/env php
<?php
/**
 * Test script: Update ExternalOrderReference for a single order.
 *
 * Usage: php bin/test_update_order.php <order_id> <new_value> [--force] [--dry-run]
 * Example: php bin/test_update_order.php 15491 "6311-2218-02-A" --dry-run
 * Example: php bin/test_update_order.php 15491 "6311-2218-02-A" --force
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Parse arguments
$orderId = null;
$newValue = null;
$force = false;
$dryRun = false;

$positionalArgs = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } else {
        $positionalArgs[] = $arg;
    }
}

if (count($positionalArgs) >= 1) {
    $orderId = (int) $positionalArgs[0];
}
if (count($positionalArgs) >= 2) {
    $newValue = $positionalArgs[1];
}

if ($orderId === null || $orderId <= 0 || $newValue === null) {
    echo "Usage: php bin/test_update_order.php <order_id> <new_value> [--force] [--dry-run]\n";
    echo "\nArguments:\n";
    echo "  order_id   Mintsoft order ID\n";
    echo "  new_value  The correct ExternalOrderReference value\n";
    echo "\nOptions:\n";
    echo "  --dry-run  Show what would be changed without making changes\n";
    echo "  --force    Skip confirmation prompt\n";
    echo "\nExample: php bin/test_update_order.php 15491 \"6311-2218-02-A\" --dry-run\n";
    exit(1);
}

$client = new Client([
    'base_uri' => $config['mintsoft']['api_url'],
    'timeout' => 30,
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],
]);

/**
 * Fetch order from Mintsoft
 */
function fetchOrder(Client $client, string $apiKey, int $orderId): ?array
{
    try {
        $response = $client->get("/api/Order/{$orderId}", [
            'query' => ['APIKey' => $apiKey],
        ]);
        return json_decode((string) $response->getBody(), true);
    } catch (\Throwable $e) {
        echo "Error fetching order: " . $e->getMessage() . "\n";
        return null;
    }
}

echo "=== Mintsoft ExternalOrderReference Update Test ===\n\n";

// Step 1: Fetch the order
echo "Step 1: Fetching order {$orderId}...\n";
$order = fetchOrder($client, $config['mintsoft']['api_key'], $orderId);

if (!$order) {
    exit(1);
}

// Extract the relevant fields
$id = $order['ID'] ?? $order['Id'] ?? null;
$orderNumber = $order['OrderNumber'] ?? null;
$currentExternalRef = $order['ExternalOrderReference'] ?? null;

echo "\nCurrent values:\n";
echo "  Mintsoft ID:              {$id}\n";
echo "  OrderNumber:              {$orderNumber}\n";
echo "  ExternalOrderReference:   {$currentExternalRef}\n\n";

// Step 2: Check if update is needed
if ($currentExternalRef === $newValue) {
    echo "No update needed - ExternalOrderReference already has the target value.\n";
    exit(0);
}

echo "Proposed change:\n";
echo "  ExternalOrderReference: \"{$currentExternalRef}\" => \"{$newValue}\"\n\n";

$payload = ['ExternalOrderReference' => $newValue];

if ($dryRun) {
    echo "POST /api/Order/{$orderId} payload:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "[DRY RUN] No changes made.\n";
    exit(0);
}

// Step 3: Confirm unless --force
if (!$force) {
    echo "Proceed with update? [y/N]: ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);

    if (trim(strtolower($line)) !== 'y') {
        echo "Aborted.\n";
        exit(0);
    }
}

// Step 4: POST the update
echo "\nStep 2: Posting update to Mintsoft...\n";
echo "POST /api/Order/{$orderId}\n";
echo "Payload: " . json_encode($payload) . "\n\n";

try {
    $response = $client->post("/api/Order/{$orderId}", [
        'query' => ['APIKey' => $config['mintsoft']['api_key']],
        'json' => $payload,
    ]);

    $statusCode = $response->getStatusCode();
    $responseBody = (string) $response->getBody();

    echo "Response status: {$statusCode}\n";
    echo "Response body:\n{$responseBody}\n\n";

    if ($statusCode >= 200 && $statusCode < 300) {
        echo "Update appears successful!\n\n";
    }

} catch (\GuzzleHttp\Exception\RequestException $e) {
    echo "API Error: " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $statusCode = $e->getResponse()->getStatusCode();
        $errorBody = (string) $e->getResponse()->getBody();
        echo "Status: {$statusCode}\n";
        echo "Response:\n{$errorBody}\n\n";
    }
    exit(1);
}

// Step 5: Verify the change
echo "Step 3: Verifying change...\n";
sleep(1);

$updatedOrder = fetchOrder($client, $config['mintsoft']['api_key'], $orderId);

if ($updatedOrder) {
    $newExternalRef = $updatedOrder['ExternalOrderReference'] ?? null;

    echo "\nAfter update:\n";
    echo "  ExternalOrderReference:  {$newExternalRef}\n\n";

    if ($newExternalRef === $newValue) {
        echo "SUCCESS: ExternalOrderReference updated correctly.\n";
    } else {
        echo "WARNING: Value may not have updated correctly.\n";
        echo "Expected: {$newValue}\n";
        echo "Got: {$newExternalRef}\n";
    }
}
