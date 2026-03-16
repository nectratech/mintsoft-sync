#!/usr/bin/env php
<?php
/**
 * Test SellerFlex Stock Sync
 *
 * Tests the SellerFlex stock sync flow with various options for debugging.
 *
 * Usage:
 *   php bin/test_sellerflex_stock.php                          # Full test (fetches from APIs, shows what would update)
 *   php bin/test_sellerflex_stock.php --dry-run                # Same as above, doesn't update Mintsoft
 *   php bin/test_sellerflex_stock.php --skip-mintsoft-fetch    # Use sample SKUs instead of fetching from Mintsoft
 *   php bin/test_sellerflex_stock.php --skip-mintsoft-update   # Fetch data but don't update Mintsoft
 *   php bin/test_sellerflex_stock.php --sku SKU123             # Test specific SKU only
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\ErrorLogger;
use MintsoftSync\IkonicClient;
use MintsoftSync\MintsoftClient;
use MintsoftSync\RateLimiter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv, true) || in_array('-n', $argv, true);
$skipMintsoftFetch = in_array('--skip-mintsoft-fetch', $argv, true);
$skipMintsoftUpdate = in_array('--skip-mintsoft-update', $argv, true) || $dryRun;
$help = in_array('--help', $argv, true) || in_array('-h', $argv, true);

// Parse --sku argument
$testSku = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--sku' && isset($argv[$i + 1])) {
        $testSku = $argv[$i + 1];
    }
}

if ($help) {
    echo <<<HELP
Test SellerFlex Stock Sync - Tests the stock sync flow

Usage:
  php bin/test_sellerflex_stock.php [options]

Options:
  --dry-run, -n           Show what would be updated without making changes
  --skip-mintsoft-fetch   Use sample SKUs instead of fetching from Mintsoft
  --skip-mintsoft-update  Fetch all data but don't update Mintsoft stock
  --sku <SKU>             Test a specific SKU only
  --help, -h              Show this help message

Examples:
  php bin/test_sellerflex_stock.php --dry-run
      Fetch data from both APIs, show what would be updated

  php bin/test_sellerflex_stock.php --skip-mintsoft-fetch
      Use sample SKUs, fetch FBA quantities, show what would update

  php bin/test_sellerflex_stock.php --sku 27US550-W
      Test sync for a specific SKU

HELP;
    exit(0);
}

// Load config
$config = require __DIR__ . '/../config/config.php';

// SellerFlex specific config
$sellerFlexCategory = 'SellerFlex';
$warehouseId = 9;

echo "=== Test SellerFlex Stock Sync ===\n\n";

echo "Options:\n";
echo "  Dry run:              " . ($dryRun ? 'Yes' : 'No') . "\n";
echo "  Skip Mintsoft fetch:  " . ($skipMintsoftFetch ? 'Yes' : 'No') . "\n";
echo "  Skip Mintsoft update: " . ($skipMintsoftUpdate ? 'Yes' : 'No') . "\n";
echo "  Test SKU:             " . ($testSku ?? 'All SellerFlex SKUs') . "\n";
echo "\n";

echo "Configuration:\n";
echo "  Mintsoft API:   {$config['mintsoft']['api_url']}\n";
echo "  Ikonic API:     {$config['ikonic']['api_url']}\n";
echo "  Category:       {$sellerFlexCategory}\n";
echo "  Warehouse ID:   {$warehouseId}\n";
echo "\n";

// Setup logger
$logger = new Logger('sellerflex-test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// Setup error logger (minimal for testing)
$errorLogger = new ErrorLogger($config['error_hub'], $logger);

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

// Initialise rate limiters
$mintsoftRateLimiter = new RateLimiter(
    $redis,
    'ratelimit:mintsoft',
    $config['rate_limit']['requests'],
    $config['rate_limit']['per_seconds']
);

$ikonicRateLimiter = new RateLimiter(
    $redis,
    'ratelimit:ikonic',
    $config['rate_limit']['requests'],
    $config['rate_limit']['per_seconds']
);

// Initialise clients
$mintsoftClient = new MintsoftClient($config['mintsoft'], $mintsoftRateLimiter, $errorLogger);
$ikonicClient = new IkonicClient($config['ikonic'], $ikonicRateLimiter);

echo str_repeat('-', 80) . "\n";
echo "Step 1: Get SellerFlex Products from Mintsoft\n";
echo str_repeat('-', 80) . "\n\n";

$sellerFlexProducts = [];

if ($skipMintsoftFetch) {
    // Use sample SKUs for testing
    echo "Using sample SKUs (--skip-mintsoft-fetch)\n\n";

    if ($testSku) {
        $sellerFlexProducts[$testSku] = [
            'sku' => $testSku,
            'product_id' => null,
            'product_name' => 'Test Product',
            'current_stock' => 0,
        ];
    } else {
        // Sample SKUs - replace with actual SellerFlex SKUs if known
        $sampleSkus = [
            '27US550-W',
            '32GN650-B',
            '34WN80C-B',
        ];

        foreach ($sampleSkus as $sku) {
            $sellerFlexProducts[$sku] = [
                'sku' => $sku,
                'product_id' => null,
                'product_name' => 'Sample Product',
                'current_stock' => 0,
            ];
        }
    }
} else {
    echo "Fetching products from Mintsoft...\n";

    try {
        $allProducts = $mintsoftClient->getAllProducts();
        echo "Fetched " . count($allProducts) . " total products\n\n";

        foreach ($allProducts as $product) {
            $isSellerFlex = false;

            // Check ProductInCategories array for SellerFlex category
            if (!empty($product['ProductInCategories']) && is_array($product['ProductInCategories'])) {
                foreach ($product['ProductInCategories'] as $categoryAssignment) {
                    $categoryName = $categoryAssignment['ProductCategory']['Name'] ?? null;
                    if ($categoryName === $sellerFlexCategory) {
                        $isSellerFlex = true;
                        break;
                    }
                }
            }

            if ($isSellerFlex) {
                $sku = $product['SKU'] ?? null;

                // Skip if testing specific SKU and this isn't it
                if ($testSku && $sku !== $testSku) {
                    continue;
                }

                if (!empty($sku)) {
                    $sellerFlexProducts[$sku] = [
                        'sku' => $sku,
                        'product_id' => $product['ID'] ?? $product['Id'] ?? null,
                        'product_name' => $product['Name'] ?? $product['ProductName'] ?? null,
                        'current_stock' => $product['OnHandStock'] ?? $product['QuantityOnHand'] ?? 0,
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        echo "ERROR: Failed to fetch products - " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "Found " . count($sellerFlexProducts) . " SellerFlex product(s)\n\n";

if (empty($sellerFlexProducts)) {
    echo "No SellerFlex products found. Nothing to test.\n";
    exit(0);
}

echo "SellerFlex Products:\n";
foreach ($sellerFlexProducts as $sku => $product) {
    printf("  %-20s | ID: %-8s | Stock: %4d | %s\n",
        $sku,
        $product['product_id'] ?? 'N/A',
        $product['current_stock'],
        substr($product['product_name'] ?? '', 0, 40)
    );
}
echo "\n";

echo str_repeat('-', 80) . "\n";
echo "Step 2: Get FBA Quantities from Ikonic\n";
echo str_repeat('-', 80) . "\n\n";

$skuList = array_keys($sellerFlexProducts);
echo "Requesting FBA quantities for " . count($skuList) . " SKU(s)...\n";
echo "SKUs: " . implode(', ', $skuList) . "\n\n";

try {
    $fbaResult = $ikonicClient->getFbaQuantitiesBulk($skuList);

    if (!$fbaResult['success']) {
        echo "ERROR: Failed to fetch FBA quantities\n";
        echo "Error: " . ($fbaResult['error'] ?? 'Unknown error') . "\n";
        exit(1);
    }

    $fbaQuantities = $fbaResult['quantities'] ?? [];
    echo "Received FBA quantities for " . count($fbaQuantities) . " SKU(s)\n\n";

    echo "FBA Quantities:\n";
    foreach ($fbaQuantities as $sku => $qty) {
        printf("  %-20s => %4d\n", $sku, $qty);
    }

    // Show SKUs with no FBA data
    $missingSkus = array_diff($skuList, array_keys($fbaQuantities));
    if (!empty($missingSkus)) {
        echo "\nSKUs with no FBA data:\n";
        foreach ($missingSkus as $sku) {
            echo "  {$sku}\n";
        }
    }
    echo "\n";

} catch (\Throwable $e) {
    echo "ERROR: Failed to fetch FBA quantities - " . $e->getMessage() . "\n";
    exit(1);
}

echo str_repeat('-', 80) . "\n";
echo "Step 3: Compare and Determine Updates\n";
echo str_repeat('-', 80) . "\n\n";

$stockUpdates = [];
$unchanged = [];

foreach ($sellerFlexProducts as $sku => $product) {
    $fbaQty = $fbaQuantities[$sku] ?? null;
    $currentQty = (int) $product['current_stock'];

    if ($fbaQty === null) {
        echo "  {$sku}: No FBA data - SKIP\n";
        continue;
    }

    $newQty = (int) $fbaQty;

    if ($currentQty !== $newQty) {
        $diff = $newQty - $currentQty;
        $diffStr = $diff > 0 ? "+{$diff}" : (string) $diff;
        echo "  {$sku}: {$currentQty} => {$newQty} ({$diffStr}) - WILL UPDATE\n";

        $stockUpdates[] = [
            'SKU' => $sku,
            'WarehouseId' => $warehouseId,
            'Quantity' => $newQty,
        ];
    } else {
        echo "  {$sku}: {$currentQty} => {$newQty} - NO CHANGE\n";
        $unchanged[] = $sku;
    }
}

echo "\nSummary:\n";
echo "  Updates needed:  " . count($stockUpdates) . "\n";
echo "  Unchanged:       " . count($unchanged) . "\n";
echo "\n";

if (empty($stockUpdates)) {
    echo "No stock updates needed.\n";
    exit(0);
}

echo str_repeat('-', 80) . "\n";
echo "Step 4: Update Mintsoft Stock\n";
echo str_repeat('-', 80) . "\n\n";

if ($skipMintsoftUpdate) {
    echo "[SKIP] Mintsoft update skipped (--skip-mintsoft-update or --dry-run)\n\n";
    echo "Would have sent this payload to POST /api/Product/BulkOnHandStockUpdate:\n";
    echo json_encode($stockUpdates, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "Sending stock updates to Mintsoft...\n\n";

try {
    $result = $mintsoftClient->bulkUpdateStock($stockUpdates);

    echo "Response:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

    if (!empty($result['Success']) || (isset($result['Success']) && $result['Success'] === true)) {
        echo "SUCCESS: Stock updated in Mintsoft\n";
    } else {
        echo "FAILED: " . ($result['Message'] ?? 'Unknown error') . "\n";
        exit(1);
    }

} catch (\Throwable $e) {
    echo "ERROR: Failed to update Mintsoft - " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n" . str_repeat('-', 80) . "\n";
echo "Test completed successfully.\n";
exit(0);
