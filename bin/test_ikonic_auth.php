#!/usr/bin/env php
<?php
/**
 * Ikonic API Authentication Diagnostic
 *
 * Tests all Ikonic endpoints to diagnose authentication issues.
 * Shows exact headers and raw responses for each endpoint.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\MessageFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// Load config
$config = require __DIR__ . '/../config/config.php';

$apiUrl = rtrim($config['ikonic']['api_url'] ?? 'https://api.ikonic.com', '/');
$apiKey = $config['ikonic']['api_key'] ?? '';

echo "=== Ikonic API Authentication Diagnostic ===\n\n";

echo "Configuration:\n";
echo "  API URL: {$apiUrl}\n";
echo "  API Key: " . substr($apiKey, 0, 8) . "..." . substr($apiKey, -4) . " (length: " . strlen($apiKey) . ")\n";
echo "\n";

// Check for whitespace or encoding issues in API key
echo "API Key Analysis:\n";
echo "  Has leading whitespace: " . (ltrim($apiKey) !== $apiKey ? 'YES' : 'No') . "\n";
echo "  Has trailing whitespace: " . (rtrim($apiKey) !== $apiKey ? 'YES' : 'No') . "\n";
echo "  Has newlines: " . (strpos($apiKey, "\n") !== false || strpos($apiKey, "\r") !== false ? 'YES' : 'No') . "\n";
echo "  First 20 chars hex: " . bin2hex(substr($apiKey, 0, 20)) . "\n";
echo "\n";

// Create a basic HTTP client for raw testing
$client = new Client([
    'base_uri' => $apiUrl,
    'timeout' => 30,
    'connect_timeout' => 10,
    'http_errors' => false, // Don't throw on 4xx/5xx
]);

// Default headers we use
$defaultHeaders = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
    'X-API-Key' => $apiKey,
];

echo str_repeat('=', 80) . "\n";
echo "Testing Endpoints\n";
echo str_repeat('=', 80) . "\n\n";

// Define test cases
$testCases = [
    [
        'name' => 'GET /3pl/get-external-order-id',
        'method' => 'GET',
        'endpoint' => '/3pl/get-external-order-id',
        'query' => ['second_ref' => 'TEST123'],
        'body' => null,
    ],
    [
        'name' => 'POST /3pl/update-order (test payload)',
        'method' => 'POST',
        'endpoint' => '/3pl/update-order',
        'query' => null,
        'body' => [
            'OrderID' => '#TEST-DIAG-001',
            'TrackingNumber' => 'TEST123456',
            'SerialNumber' => 'TESTSERIAL001',
            'SKU' => 'TEST-SKU',
            'ReferenceNumber' => '#TEST-REF-001',
        ],
    ],
    [
        'name' => 'GET /3pl/get-fba-fulfillable-quantities (single SKU)',
        'method' => 'GET',
        'endpoint' => '/3pl/get-fba-fulfillable-quantities',
        'query' => ['sku' => '58A6K_SF'],
        'body' => null,
    ],
    [
        'name' => 'POST /3pl/get-fba-fulfillable-quantities/bulk',
        'method' => 'POST',
        'endpoint' => '/3pl/get-fba-fulfillable-quantities/bulk',
        'query' => null,
        'body' => ['skus' => ['58A6K_SF', '27US550-W']],
    ],
];

$results = [];

foreach ($testCases as $index => $test) {
    $num = $index + 1;
    echo "[{$num}/" . count($testCases) . "] {$test['name']}\n";
    echo str_repeat('-', 60) . "\n";

    $options = [
        'headers' => $defaultHeaders,
    ];

    if ($test['query']) {
        $options['query'] = $test['query'];
    }

    if ($test['body']) {
        $options['json'] = $test['body'];
    }

    // Show what we're sending
    echo "Request:\n";
    echo "  Method: {$test['method']}\n";
    echo "  URL: {$apiUrl}{$test['endpoint']}\n";
    if ($test['query']) {
        echo "  Query: " . http_build_query($test['query']) . "\n";
    }
    echo "  Headers:\n";
    foreach ($defaultHeaders as $key => $value) {
        if ($key === 'X-API-Key') {
            echo "    {$key}: " . substr($value, 0, 8) . "...\n";
        } else {
            echo "    {$key}: {$value}\n";
        }
    }
    if ($test['body']) {
        echo "  Body: " . json_encode($test['body']) . "\n";
    }
    echo "\n";

    try {
        $response = $client->request($test['method'], $test['endpoint'], $options);

        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        $body = (string) $response->getBody();
        $responseHeaders = $response->getHeaders();

        echo "Response:\n";
        echo "  Status: {$statusCode} {$reasonPhrase}\n";
        echo "  Headers:\n";
        foreach ($responseHeaders as $name => $values) {
            echo "    {$name}: " . implode(', ', $values) . "\n";
        }
        echo "  Body: " . substr($body, 0, 500);
        if (strlen($body) > 500) {
            echo "... (truncated)";
        }
        echo "\n";

        $success = $statusCode >= 200 && $statusCode < 300;
        $results[] = [
            'name' => $test['name'],
            'status' => $statusCode,
            'success' => $success,
        ];

        if ($success) {
            echo "  Result: SUCCESS\n";
        } else {
            echo "  Result: FAILED ({$statusCode})\n";
        }

    } catch (GuzzleException $e) {
        echo "Response:\n";
        echo "  ERROR: " . $e->getMessage() . "\n";

        $results[] = [
            'name' => $test['name'],
            'status' => 0,
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }

    echo "\n";
}

// Now test with alternative auth methods
echo str_repeat('=', 80) . "\n";
echo "Testing Alternative Authentication Methods\n";
echo str_repeat('=', 80) . "\n\n";

$altAuthTests = [
    [
        'name' => 'Authorization: Bearer header',
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ],
    ],
    [
        'name' => 'Authorization: ApiKey header',
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'ApiKey ' . $apiKey,
        ],
    ],
    [
        'name' => 'api_key query parameter',
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
        'extra_query' => ['api_key' => $apiKey],
    ],
    [
        'name' => 'API-Key header (hyphenated)',
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'API-Key' => $apiKey,
        ],
    ],
];

// Test alternative auth on the failing endpoint
$failingEndpoint = '/3pl/get-fba-fulfillable-quantities/bulk';
$failingBody = ['skus' => ['58A6K_SF']];

foreach ($altAuthTests as $index => $altAuth) {
    $num = $index + 1;
    echo "[{$num}/" . count($altAuthTests) . "] POST {$failingEndpoint} with {$altAuth['name']}\n";
    echo str_repeat('-', 60) . "\n";

    $options = [
        'headers' => $altAuth['headers'],
        'json' => $failingBody,
    ];

    if (isset($altAuth['extra_query'])) {
        $options['query'] = $altAuth['extra_query'];
    }

    echo "Headers being sent:\n";
    foreach ($altAuth['headers'] as $key => $value) {
        if (strpos(strtolower($key), 'key') !== false || strpos(strtolower($key), 'auth') !== false) {
            echo "  {$key}: " . substr($value, 0, 20) . "...\n";
        } else {
            echo "  {$key}: {$value}\n";
        }
    }

    try {
        $response = $client->request('POST', $failingEndpoint, $options);
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        echo "Result: {$statusCode} - " . substr($body, 0, 200) . "\n";

        if ($statusCode >= 200 && $statusCode < 300) {
            echo "*** THIS METHOD WORKS! ***\n";
        }

    } catch (GuzzleException $e) {
        echo "Result: ERROR - " . $e->getMessage() . "\n";
    }

    echo "\n";
}

// Summary
echo str_repeat('=', 80) . "\n";
echo "DIAGNOSTIC SUMMARY\n";
echo str_repeat('=', 80) . "\n\n";

echo "Endpoint Results:\n";
foreach ($results as $result) {
    $status = $result['success'] ? 'OK' : 'FAILED';
    $code = $result['status'] ?: 'ERR';
    echo sprintf("  [%s] %-50s %s\n", $code, $result['name'], $status);
}
echo "\n";

// Check for patterns
$workingEndpoints = array_filter($results, fn($r) => $r['success']);
$failingEndpoints = array_filter($results, fn($r) => !$r['success']);

if (count($workingEndpoints) > 0 && count($failingEndpoints) > 0) {
    echo "ANALYSIS:\n";
    echo "  Some endpoints work, some fail. This suggests:\n";
    echo "  - API key is valid (some endpoints accept it)\n";
    echo "  - Failing endpoints may require different permissions\n";
    echo "  - Or failing endpoints use a different auth mechanism\n";
    echo "\n";
    echo "RECOMMENDATION:\n";
    echo "  Contact Ikonic support to verify:\n";
    echo "  1. Does your API key have access to the FBA quantities endpoint?\n";
    echo "  2. Is there a different auth method required for that endpoint?\n";
    echo "  3. Are there IP restrictions on certain endpoints?\n";
}

echo "\n";
