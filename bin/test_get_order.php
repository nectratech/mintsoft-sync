#!/usr/bin/env php
<?php
/**
 * Test script: Fetch a single order from Mintsoft and display the JSON.
 *
 * Usage: php bin/test_get_order.php <order_id>
 * Example: php bin/test_get_order.php 12345
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;

// Load config
$config = require __DIR__ . '/../config/config.php';

// Check for order ID argument
if ($argc < 2) {
    echo "Usage: php bin/test_get_order.php <order_id>\n";
    echo "Example: php bin/test_get_order.php 12345\n";
    exit(1);
}

$orderId = (int) $argv[1];

if ($orderId <= 0) {
    echo "Error: Order ID must be a positive integer\n";
    exit(1);
}

echo "Fetching order {$orderId} from Mintsoft...\n\n";

$client = new Client([
    'base_uri' => $config['mintsoft']['api_url'],
    'timeout' => 30,
]);

try {
    $response = $client->get("/api/Order/{$orderId}", [
        'query' => [
            'APIKey' => $config['mintsoft']['api_key'],
        ],
    ]);

    $body = (string) $response->getBody();
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Error: Invalid JSON response\n";
        echo $body . "\n";
        exit(1);
    }

    // Pretty print the full JSON
    echo "=== FULL ORDER JSON ===\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

    // Highlight the reference fields we care about
    echo "=== KEY REFERENCE FIELDS ===\n";
    $fields = [
        'ID', 'Id',
        'OrderNumber',
        'ReferenceNum', 'ReferenceNumber', 'Reference',
        'SecondaryReference', 'SecondaryReferenceNum',
        'ExternalReferenceNum', 'ExternalReference', 'ExternalOrderReference',
    ];

    foreach ($fields as $field) {
        if (isset($data[$field])) {
            echo sprintf("  %-25s => %s\n", $field, json_encode($data[$field]));
        }
    }

    echo "\n";

} catch (\GuzzleHttp\Exception\RequestException $e) {
    echo "API Error: " . $e->getMessage() . "\n";
    if ($e->hasResponse()) {
        echo "Response: " . (string) $e->getResponse()->getBody() . "\n";
    }
    exit(1);
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
