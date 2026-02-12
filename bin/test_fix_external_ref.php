#!/usr/bin/env php
<?php
/**
 * Test script: Fix ExternalOrderReference for a single order.
 *
 * Flow:
 * 1. Fetch order from Mintsoft (get OrderNumber and current ExternalOrderReference)
 * 2. Call Ikonic API to get the correct external_order_id
 * 3. Update Mintsoft with the correct ExternalOrderReference
 *
 * Usage: php bin/test_fix_external_ref.php <mintsoft_order_id> [--force] [--dry-run]
 * Example: php bin/test_fix_external_ref.php 15577 --dry-run
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Parse arguments
$mintsoftOrderId = null;
$force = false;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (is_numeric($arg)) {
        $mintsoftOrderId = (int) $arg;
    }
}

if ($mintsoftOrderId === null || $mintsoftOrderId <= 0) {
    echo "Usage: php bin/test_fix_external_ref.php <mintsoft_order_id> [--force] [--dry-run]\n";
    echo "\nOptions:\n";
    echo "  --dry-run  Show what would be changed without making changes\n";
    echo "  --force    Skip confirmation prompt\n";
    echo "\nExample: php bin/test_fix_external_ref.php 15577 --dry-run\n";
    exit(1);
}

// Setup HTTP clients
$mintsoftClient = new Client([
    'base_uri' => $config['mintsoft']['api_url'],
    'timeout' => 30,
    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],
]);

$ikonicClient = new Client([
    'base_uri' => $config['ikonic']['api_url'],
    'timeout' => 30,
    'headers' => [
        'Accept' => 'application/json',
        'X-API-Key' => $config['ikonic']['api_key'],
    ],
]);

echo "=== Fix ExternalOrderReference Test ===\n\n";

// ============================================
// Step 1: Fetch order from Mintsoft
// ============================================
echo "Step 1: Fetching order {$mintsoftOrderId} from Mintsoft...\n";

try {
    $response = $mintsoftClient->get("/api/Order/{$mintsoftOrderId}", [
        'query' => ['APIKey' => $config['mintsoft']['api_key']],
    ]);
    $order = json_decode((string) $response->getBody(), true);
} catch (\Throwable $e) {
    echo "Error fetching from Mintsoft: " . $e->getMessage() . "\n";
    exit(1);
}

$orderNumber = $order['OrderNumber'] ?? null;
$currentExternalRef = $order['ExternalOrderReference'] ?? null;

echo "\nMintsoft order details:\n";
echo "  Mintsoft ID:              {$mintsoftOrderId}\n";
echo "  OrderNumber:              {$orderNumber}\n";
echo "  Current ExternalOrderRef: {$currentExternalRef}\n\n";

if (!$currentExternalRef) {
    echo "Error: ExternalOrderReference is empty - cannot look up in Ikonic\n";
    exit(1);
}

// ============================================
// Step 2: Call Ikonic API to get correct external_order_id
// ============================================
echo "Step 2: Calling Ikonic API with second_ref={$currentExternalRef}...\n";

try {
    $response = $ikonicClient->get("/3pl/get-external-order-id", [
        'query' => ['second_ref' => $currentExternalRef],
    ]);
    $ikonicData = json_decode((string) $response->getBody(), true);

    echo "Ikonic response: " . json_encode($ikonicData) . "\n\n";

} catch (\GuzzleHttp\Exception\RequestException $e) {
    $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
    $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

    echo "Ikonic API error (status {$statusCode}): {$responseBody}\n";
    exit(1);
} catch (\Throwable $e) {
    echo "Error calling Ikonic: " . $e->getMessage() . "\n";
    exit(1);
}

$correctExternalRef = $ikonicData['external_order_id'] ?? null;

if (!$correctExternalRef) {
    echo "Error: Ikonic did not return an external_order_id\n";
    echo "Response was: " . json_encode($ikonicData) . "\n";
    exit(1);
}

echo "Correct ExternalOrderReference from Ikonic: {$correctExternalRef}\n\n";

// ============================================
// Step 3: Compare and update if needed
// ============================================
echo "Step 3: Comparing values...\n";

if ($currentExternalRef === $correctExternalRef) {
    echo "\nNo update needed - ExternalOrderReference is already correct!\n";
    exit(0);
}

echo "\nProposed change:\n";
echo "  ExternalOrderReference: \"{$currentExternalRef}\" => \"{$correctExternalRef}\"\n\n";

if ($dryRun) {
    echo "POST /api/Order/{$mintsoftOrderId} payload:\n";
    echo json_encode(['ExternalOrderReference' => $correctExternalRef], JSON_PRETTY_PRINT) . "\n\n";
    echo "[DRY RUN] No changes made.\n";
    exit(0);
}

// Confirm unless --force
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

// ============================================
// Step 4: Update Mintsoft
// ============================================
echo "\nStep 4: Updating Mintsoft...\n";

$payload = ['ExternalOrderReference' => $correctExternalRef];
echo "POST /api/Order/{$mintsoftOrderId}\n";
echo "Payload: " . json_encode($payload) . "\n\n";

try {
    $response = $mintsoftClient->post("/api/Order/{$mintsoftOrderId}", [
        'query' => ['APIKey' => $config['mintsoft']['api_key']],
        'json' => $payload,
    ]);

    $statusCode = $response->getStatusCode();
    $responseBody = (string) $response->getBody();

    echo "Response status: {$statusCode}\n";

    if ($responseBody) {
        echo "Response body: {$responseBody}\n";
    }

    echo "\n";

    if ($statusCode >= 200 && $statusCode < 300) {
        echo "Update appears successful!\n\n";
    }

} catch (\GuzzleHttp\Exception\RequestException $e) {
    echo "Mintsoft API Error: " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        $statusCode = $e->getResponse()->getStatusCode();
        $errorBody = (string) $e->getResponse()->getBody();
        echo "Status: {$statusCode}\n";
        echo "Response: {$errorBody}\n\n";
    }
    exit(1);
}

// ============================================
// Step 5: Verify the change
// ============================================
echo "Step 5: Verifying change...\n";
sleep(1);

try {
    $response = $mintsoftClient->get("/api/Order/{$mintsoftOrderId}", [
        'query' => ['APIKey' => $config['mintsoft']['api_key']],
    ]);
    $updatedOrder = json_decode((string) $response->getBody(), true);
    $newExternalRef = $updatedOrder['ExternalOrderReference'] ?? null;

    echo "\nAfter update:\n";
    echo "  ExternalOrderReference: {$newExternalRef}\n\n";

    if ($newExternalRef === $correctExternalRef) {
        echo "SUCCESS: ExternalOrderReference updated correctly!\n";
    } else {
        echo "WARNING: Value may not have updated correctly.\n";
        echo "Expected: {$correctExternalRef}\n";
        echo "Got: {$newExternalRef}\n";
    }
} catch (\Throwable $e) {
    echo "Error verifying: " . $e->getMessage() . "\n";
}
