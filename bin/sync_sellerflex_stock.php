#!/usr/bin/env php
<?php
/**
 * Sync SellerFlex Stock to Mintsoft
 *
 * Fetches FBA fulfillable quantities from Ikonic API and updates Mintsoft
 * on-hand stock for SellerFlex products.
 *
 * Flow:
 * 1. Fetch all products from Mintsoft where ProductCategory.Name = "SellerFlex"
 * 2. Extract SKUs and call Ikonic API to get FBA fulfillable quantities
 * 3. Update Mintsoft on-hand stock using BulkOnHandStockUpdate
 *
 * Run via cron, e.g.: 0,5,10,15... * * * * /usr/bin/php /path/to/bin/sync_sellerflex_stock.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MintsoftSync\Database;
use MintsoftSync\ErrorLogger;
use MintsoftSync\IkonicClient;
use MintsoftSync\MintsoftClient;
use MintsoftSync\RateLimiter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Predis\Client as RedisClient;

// Load config
$config = require __DIR__ . '/../config/config.php';

// SellerFlex specific config
$sellerFlexCategory = 'SellerFlex';
$warehouseId = 9; // Mintsoft warehouse ID for SellerFlex stock

// Setup local logger
$localLogger = new Logger('sellerflex-sync');
$localLogger->pushHandler(new StreamHandler(__DIR__ . '/../logs/sellerflex_sync.log', Logger::DEBUG));
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
$database = new Database($config['database']);

// ============================================
// Start sync
// ============================================
$localLogger->info('========================================');
$localLogger->info('SellerFlex Stock Sync Started', [
    'timestamp' => date('Y-m-d H:i:s'),
]);

$runId = null;
$skusFound = 0;
$skusWithFbaData = 0;
$skusUpdated = 0;
$skusUnchanged = 0;
$skusFailed = 0;

try {
    // Start sync run tracking
    $runId = $database->startSellerFlexSyncRun();

    // Step 1: Fetch all products from Mintsoft
    $localLogger->info('Fetching products from Mintsoft...');
    $allProducts = $mintsoftClient->getAllProducts();
    $localLogger->info('Fetched products from Mintsoft', ['total' => count($allProducts)]);

    // Step 2: Filter for SellerFlex products
    // Products have a ProductInCategories array containing category assignments
    $sellerFlexProducts = [];
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

    $skusFound = count($sellerFlexProducts);
    $localLogger->info('Found SellerFlex products', ['count' => $skusFound]);

    if ($skusFound === 0) {
        $localLogger->info('No SellerFlex products found - nothing to sync');
        $database->completeSellerFlexSyncRun($runId, 0, 0, 0, 0, 0);
        $localLogger->info('========================================');
        exit(0);
    }

    // Track SKUs in database
    foreach ($sellerFlexProducts as $sku => $product) {
        $database->upsertSellerFlexSku(
            $sku,
            $product['product_id'],
            $product['product_name']
        );
    }

    // Step 3: Get FBA quantities from Ikonic
    $skuList = array_keys($sellerFlexProducts);
    $localLogger->info('Fetching FBA quantities from Ikonic...', ['sku_count' => count($skuList)]);

    $fbaResult = $ikonicClient->getFbaQuantitiesBulk($skuList);

    if (!$fbaResult['success']) {
        throw new \RuntimeException('Failed to fetch FBA quantities: ' . ($fbaResult['error'] ?? 'Unknown error'));
    }

    $fbaQuantities = $fbaResult['quantities'] ?? [];
    $skusWithFbaData = count($fbaQuantities);
    $localLogger->info('Received FBA quantities', ['sku_count' => $skusWithFbaData]);

    // Step 4: Build stock update records
    $stockUpdates = [];
    foreach ($sellerFlexProducts as $sku => $product) {
        $fbaQty = $fbaQuantities[$sku] ?? null;

        if ($fbaQty === null) {
            $localLogger->debug('No FBA quantity for SKU - skipping', ['sku' => $sku]);
            continue;
        }

        $currentQty = (int) $product['current_stock'];
        $newQty = (int) $fbaQty;

        // Only update if quantity has changed
        if ($currentQty !== $newQty) {
            $stockUpdates[] = [
                'SKU' => $sku,
                'WarehouseId' => $warehouseId,
                'Quantity' => $newQty,
            ];

            $localLogger->debug('Stock change detected', [
                'sku' => $sku,
                'old_qty' => $currentQty,
                'new_qty' => $newQty,
            ]);
        } else {
            $skusUnchanged++;
            $database->updateSellerFlexSkuStock($sku, $newQty, $currentQty);
        }
    }

    if (empty($stockUpdates)) {
        $localLogger->info('No stock changes needed');
        $database->completeSellerFlexSyncRun(
            $runId,
            $skusFound,
            $skusWithFbaData,
            0,
            $skusUnchanged,
            0
        );
        $localLogger->info('Sync complete - no changes');
        $localLogger->info('========================================');
        exit(0);
    }

    // Step 5: Update Mintsoft stock in batches
    $localLogger->info('Updating stock in Mintsoft...', ['updates' => count($stockUpdates)]);

    // Process in batches of 50 to avoid overwhelming the API
    $batchSize = 50;
    $batches = array_chunk($stockUpdates, $batchSize);

    foreach ($batches as $batchIndex => $batch) {
        $localLogger->debug('Processing batch', [
            'batch' => $batchIndex + 1,
            'total_batches' => count($batches),
            'records' => count($batch),
        ]);

        $result = $mintsoftClient->bulkUpdateStock($batch);

        if (!empty($result['Success']) || (isset($result['Success']) && $result['Success'] === true)) {
            // Success - record in database
            foreach ($batch as $record) {
                $sku = $record['SKU'];
                $newQty = $record['Quantity'];
                $oldQty = $sellerFlexProducts[$sku]['current_stock'] ?? null;

                $database->updateSellerFlexSkuStock($sku, $newQty, $oldQty);
                $skusUpdated++;

                $localLogger->info('Stock updated', [
                    'sku' => $sku,
                    'old_qty' => $oldQty,
                    'new_qty' => $newQty,
                ]);
            }
        } else {
            // Failed - record errors
            $errorMessage = $result['Message'] ?? 'Unknown error';
            $localLogger->error('Batch update failed', [
                'batch' => $batchIndex + 1,
                'error' => $errorMessage,
            ]);

            foreach ($batch as $record) {
                $database->recordSellerFlexSkuError($record['SKU'], $errorMessage);
                $skusFailed++;
            }

            $errorLogger->warning('SellerFlex stock batch update failed', [
                'batch' => $batchIndex + 1,
                'error' => $errorMessage,
                'skus' => array_column($batch, 'SKU'),
            ]);
        }
    }

    // Complete sync run
    $database->completeSellerFlexSyncRun(
        $runId,
        $skusFound,
        $skusWithFbaData,
        $skusUpdated,
        $skusUnchanged,
        $skusFailed
    );

    // Log summary
    $localLogger->info('SellerFlex stock sync completed', [
        'skus_found' => $skusFound,
        'skus_with_fba_data' => $skusWithFbaData,
        'skus_updated' => $skusUpdated,
        'skus_unchanged' => $skusUnchanged,
        'skus_failed' => $skusFailed,
    ]);

} catch (\Throwable $e) {
    $errorLogger->logException($e, [
        'context' => 'sellerflex_stock_sync',
    ]);

    if ($runId !== null) {
        $database->completeSellerFlexSyncRun(
            $runId,
            $skusFound,
            $skusWithFbaData,
            $skusUpdated,
            $skusUnchanged,
            $skusFailed,
            $e->getMessage()
        );
    }

    $localLogger->error('Sync failed with error', [
        'error' => $e->getMessage(),
    ]);
    $localLogger->info('========================================');
    exit(1);
}

$localLogger->info('Sync complete', [
    'timestamp' => date('Y-m-d H:i:s'),
]);
$localLogger->info('========================================');
exit($skusFailed > 0 ? 1 : 0);
