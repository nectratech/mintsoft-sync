<?php

declare(strict_types=1);

namespace MintsoftSync;

use PDO;
use PDOException;

class Database
{
    private PDO $pdo;
    private string $prefix;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['table_prefix'] ?? '';
        $this->connect();
    }

    /**
     * Establish database connection.
     */
    private function connect(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['port'],
            $this->config['database']
        );

        $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Check if connection is alive and reconnect if needed.
     * Call this before operations in long-running processes.
     */
    public function ensureConnected(): void
    {
        try {
            // Try to ping the connection
            $this->pdo->getAttribute(PDO::ATTR_SERVER_INFO);
        } catch (PDOException $e) {
            // Connection lost, reconnect
            $this->connect();
        }
    }

    /**
     * Execute a prepared statement with automatic retry on connection errors.
     *
     * @param string $sql The SQL query
     * @param array $params Parameters to bind
     * @return \PDOStatement
     */
    private function executeWithRetry(string $sql, array $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // Check if it's a "gone away" or "lost connection" error
            if (strpos($e->getMessage(), 'gone away') !== false ||
                strpos($e->getMessage(), 'Lost connection') !== false ||
                $e->getCode() === 'HY000') {

                // Reconnect and retry once
                $this->connect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }

            // Re-throw if it's not a connection error
            throw $e;
        }
    }

    /**
     * Reconnect to database. Useful for long-running workers.
     */
    public function reconnect(): void
    {
        $this->connect();
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Get table name with prefix.
     */
    private function table(string $name): string
    {
        return $this->prefix . $name;
    }

    /**
     * Sanitise a value - convert arrays to JSON string, ensure scalar types.
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitise($value)
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_object($value)) {
            return json_encode($value);
        }
        return $value;
    }

    /**
     * Sanitise all values in an array.
     */
    private function sanitiseAll(array $data): array
    {
        return array_map([$this, 'sanitise'], $data);
    }

    /**
     * Upsert an order record.
     * Returns the mintsoft_id (which is now the primary key).
     */
    public function upsertOrder(array $order): int
    {
        $table = $this->table('orders');

        $sql = "INSERT INTO {$table} (
            mintsoft_id, order_number, external_order_reference, client_id, status, channel,
            currency, total_amount, order_value, shipping_method,
            tracking_number, tracking_url, courier_service_name,
            total_items, total_weight, warehouse_id,
            customer_name, customer_email, customer_phone,
            shipping_address_1, shipping_address_2, shipping_city,
            shipping_county, shipping_postcode, shipping_country,
            comments, gift_messages, tags,
            order_date, despatch_date, created_at, updated_at
        ) VALUES (
            :mintsoft_id, :order_number, :external_order_reference, :client_id, :status, :channel,
            :currency, :total_amount, :order_value, :shipping_method,
            :tracking_number, :tracking_url, :courier_service_name,
            :total_items, :total_weight, :warehouse_id,
            :customer_name, :customer_email, :customer_phone,
            :shipping_address_1, :shipping_address_2, :shipping_city,
            :shipping_county, :shipping_postcode, :shipping_country,
            :comments, :gift_messages, :tags,
            :order_date, :despatch_date, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            order_number = VALUES(order_number),
            external_order_reference = VALUES(external_order_reference),
            status = VALUES(status),
            channel = VALUES(channel),
            currency = VALUES(currency),
            total_amount = VALUES(total_amount),
            order_value = VALUES(order_value),
            shipping_method = VALUES(shipping_method),
            tracking_number = VALUES(tracking_number),
            tracking_url = VALUES(tracking_url),
            courier_service_name = VALUES(courier_service_name),
            total_items = VALUES(total_items),
            total_weight = VALUES(total_weight),
            warehouse_id = VALUES(warehouse_id),
            customer_name = VALUES(customer_name),
            customer_email = VALUES(customer_email),
            customer_phone = VALUES(customer_phone),
            shipping_address_1 = VALUES(shipping_address_1),
            shipping_address_2 = VALUES(shipping_address_2),
            shipping_city = VALUES(shipping_city),
            shipping_county = VALUES(shipping_county),
            shipping_postcode = VALUES(shipping_postcode),
            shipping_country = VALUES(shipping_country),
            comments = VALUES(comments),
            gift_messages = VALUES(gift_messages),
            tags = VALUES(tags),
            order_date = VALUES(order_date),
            despatch_date = VALUES(despatch_date),
            updated_at = NOW()";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($this->sanitiseAll([
            'mintsoft_id' => $order['mintsoft_id'],
            'order_number' => $order['order_number'] ?? null,
            'external_order_reference' => $order['external_order_reference'] ?? null,
            'client_id' => $order['client_id'],
            'status' => $order['status'] ?? null,
            'channel' => $order['channel'] ?? null,
            'currency' => $order['currency'] ?? null,
            'total_amount' => $order['total_amount'] ?? null,
            'order_value' => $order['order_value'] ?? null,
            'shipping_method' => $order['shipping_method'] ?? null,
            'tracking_number' => $order['tracking_number'] ?? null,
            'tracking_url' => $order['tracking_url'] ?? null,
            'courier_service_name' => $order['courier_service_name'] ?? null,
            'total_items' => $order['total_items'] ?? null,
            'total_weight' => $order['total_weight'] ?? null,
            'warehouse_id' => $order['warehouse_id'] ?? null,
            'customer_name' => $order['customer_name'] ?? null,
            'customer_email' => $order['customer_email'] ?? null,
            'customer_phone' => $order['customer_phone'] ?? null,
            'shipping_address_1' => $order['shipping_address_1'] ?? null,
            'shipping_address_2' => $order['shipping_address_2'] ?? null,
            'shipping_city' => $order['shipping_city'] ?? null,
            'shipping_county' => $order['shipping_county'] ?? null,
            'shipping_postcode' => $order['shipping_postcode'] ?? null,
            'shipping_country' => $order['shipping_country'] ?? null,
            'comments' => $order['comments'] ?? null,
            'gift_messages' => $order['gift_messages'] ?? null,
            'tags' => $order['tags'] ?? null,
            'order_date' => $order['order_date'] ?? null,
            'despatch_date' => $order['despatch_date'] ?? null,
        ]));

        // Return the mintsoft_id (now the primary key)
        return (int) $order['mintsoft_id'];
    }

    /**
     * Upsert an order line record.
     * Returns the mintsoft_line_id (which is now the primary key).
     *
     * @param int $orderId The order's mintsoft_id (now the PK)
     * @param array $line The line item data
     * @return int The mintsoft_line_id
     */
    public function upsertOrderLine(int $orderId, array $line): int
    {
        $table = $this->table('order_lines');

        $sql = "INSERT INTO {$table} (
            order_id, mintsoft_line_id, product_id, sku, product_name,
            quantity, unit_price, line_total, created_at, updated_at
        ) VALUES (
            :order_id, :mintsoft_line_id, :product_id, :sku, :product_name,
            :quantity, :unit_price, :line_total, NOW(), NOW()
        ) ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id),
            product_id = VALUES(product_id),
            sku = VALUES(sku),
            product_name = VALUES(product_name),
            quantity = VALUES(quantity),
            unit_price = VALUES(unit_price),
            line_total = VALUES(line_total),
            updated_at = NOW()";

        $this->executeWithRetry($sql, $this->sanitiseAll([
            'order_id' => $orderId,
            'mintsoft_line_id' => $line['mintsoft_line_id'],
            'product_id' => $line['product_id'] ?? null,
            'sku' => $line['sku'] ?? null,
            'product_name' => $line['product_name'] ?? null,
            'quantity' => $line['quantity'] ?? 0,
            'unit_price' => $line['unit_price'] ?? null,
            'line_total' => $line['line_total'] ?? null,
        ]));

        // Return the mintsoft_line_id (now the primary key)
        return (int) $line['mintsoft_line_id'];
    }

    /**
     * Insert or update a serial record based on (order_id, serial_number).
     *
     * @param int $orderId The order's mintsoft_id (now the PK)
     * @param int|null $orderLineId The line's mintsoft_line_id (now the PK), or null if not available
     * @param array $serial The serial data
     * @return int The inserted ID, or 0 if updated (driver-dependent)
     */
    public function insertSerial(int $orderId, ?int $orderLineId, array $serial): int
    {
        $table = $this->table('serials');

        $sql = "INSERT INTO {$table} (
            order_id, order_line_id, serial_number, barcode,
            product_id, sku, batch_no, expiry_date, box_number,
            sscc_number, verified_at, created_at
        ) VALUES (
            :order_id, :order_line_id, :serial_number, :barcode,
            :product_id, :sku, :batch_no, :expiry_date, :box_number,
            :sscc_number, :verified_at, NOW()
        ) ON DUPLICATE KEY UPDATE
            order_line_id = VALUES(order_line_id),
            barcode = VALUES(barcode),
            product_id = VALUES(product_id),
            sku = VALUES(sku),
            batch_no = VALUES(batch_no),
            expiry_date = VALUES(expiry_date),
            box_number = VALUES(box_number),
            sscc_number = VALUES(sscc_number),
            verified_at = VALUES(verified_at)";

        $this->executeWithRetry($sql, $this->sanitiseAll([
            'order_id' => $orderId,
            'order_line_id' => $orderLineId,
            'serial_number' => $serial['serial_number'] ?? null,
            'barcode' => $serial['barcode'] ?? null,
            'product_id' => $serial['product_id'] ?? null,
            'sku' => $serial['sku'] ?? null,
            'batch_no' => $serial['batch_no'] ?? null,
            'expiry_date' => $serial['expiry_date'] ?? null,
            'box_number' => $serial['box_number'] ?? null,
            'sscc_number' => $serial['sscc_number'] ?? null,
            'verified_at' => $serial['verified_at'] ?? null,
        ]));

        // Returns 0 if the row was updated (driver-dependent)
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Check if an order exists by Mintsoft ID and return it.
     * Since mintsoft_id is now the primary key, this just checks existence.
     *
     * @param int $mintsoftId The mintsoft_id to look up
     * @return int|null The mintsoft_id if exists, null otherwise
     */
    public function getOrderIdByMintsoftId(int $mintsoftId): ?int
    {
        $table = $this->table('orders');
        $stmt = $this->pdo->prepare("SELECT mintsoft_id FROM {$table} WHERE mintsoft_id = ?");
        $stmt->execute([$mintsoftId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int) $result : null;
    }

    /**
     * Check if an order line exists by Mintsoft line ID and return it.
     * Since mintsoft_line_id is now the primary key, this just checks existence.
     *
     * @param int $mintsoftLineId The mintsoft_line_id to look up
     * @return int|null The mintsoft_line_id if exists, null otherwise
     */
    public function getOrderLineIdByMintsoftLineId(int $mintsoftLineId): ?int
    {
        $table = $this->table('order_lines');
        $stmt = $this->pdo->prepare("SELECT mintsoft_line_id FROM {$table} WHERE mintsoft_line_id = ?");
        $stmt->execute([$mintsoftLineId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int) $result : null;
    }

    /**
     * Record sync run.
     */
    public function recordSyncRun(string $type, int $itemsProcessed, ?string $error = null): void
    {
        $table = $this->table('sync_log');
        $sql = "INSERT INTO {$table} (sync_type, items_processed, error_message, created_at)
                VALUES (?, ?, ?, NOW())";
        $this->executeWithRetry($sql, [$type, $itemsProcessed, $error]);
    }

    /**
     * Get last successful sync time for a type.
     */
    public function getLastSyncTime(string $type): ?string
    {
        $table = $this->table('sync_log');
        $stmt = $this->pdo->prepare(
            "SELECT created_at FROM {$table}
             WHERE sync_type = ? AND error_message IS NULL
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$type]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    // ============================================
    // Ikonic Sync Methods
    // ============================================

    /**
     * Get serials that need to be synced to Ikonic.
     *
     * Returns serials where:
     * - synced_to_ikonic_at IS NULL (not yet synced)
     * - AND (no error OR error is older than 1 hour for auto-retry)
     * - Order has a tracking_number (required for Ikonic)
     * - Order has an external_order_reference (required for OrderID)
     *
     * SKU is fetched from ms_order_lines via order_line_id join.
     *
     * @param int $limit Maximum number of serials to return
     * @return array Array of serial records with order data
     */
    public function getUnsyncedSerials(int $limit = 100): array
    {
        $serialsTable = $this->table('serials');
        $ordersTable = $this->table('orders');
        $orderLinesTable = $this->table('order_lines');

        $sql = "SELECT
                    s.id AS serial_id,
                    s.serial_number,
                    COALESCE(ol.sku, s.sku) AS sku,
                    s.order_id,
                    o.external_order_reference,
                    o.tracking_number,
                    o.order_number
                FROM {$serialsTable} s
                INNER JOIN {$ordersTable} o ON s.order_id = o.mintsoft_id
                LEFT JOIN {$orderLinesTable} ol ON s.order_line_id = ol.mintsoft_line_id
                WHERE s.synced_to_ikonic_at IS NULL
                  AND (
                      s.ikonic_sync_error IS NULL
                      OR s.ikonic_sync_error_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  )
                  AND o.tracking_number IS NOT NULL
                  AND o.tracking_number != ''
                  AND o.external_order_reference IS NOT NULL
                  AND o.external_order_reference != ''
                ORDER BY s.created_at ASC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Mark a serial as successfully synced to Ikonic.
     *
     * @param int $serialId The serial record ID
     */
    public function markSerialSyncedToIkonic(int $serialId): void
    {
        $table = $this->table('serials');
        $sql = "UPDATE {$table}
                SET synced_to_ikonic_at = NOW(),
                    ikonic_sync_error = NULL,
                    ikonic_sync_error_at = NULL
                WHERE id = :id";

        $this->executeWithRetry($sql, ['id' => $serialId]);
    }

    /**
     * Mark a serial as failed to sync to Ikonic.
     *
     * @param int $serialId The serial record ID
     * @param string $error The error message
     */
    public function markSerialIkonicError(int $serialId, string $error): void
    {
        $table = $this->table('serials');
        $sql = "UPDATE {$table}
                SET ikonic_sync_error = :error,
                    ikonic_sync_error_at = NOW()
                WHERE id = :id";

        $this->executeWithRetry($sql, [
            'id' => $serialId,
            'error' => $error,
        ]);
    }

    /**
     * Clear Ikonic sync error for a serial (to retry immediately).
     *
     * @param int $serialId The serial record ID
     */
    public function clearSerialIkonicError(int $serialId): void
    {
        $table = $this->table('serials');
        $sql = "UPDATE {$table}
                SET ikonic_sync_error = NULL,
                    ikonic_sync_error_at = NULL
                WHERE id = :id";

        $this->executeWithRetry($sql, ['id' => $serialId]);
    }

    /**
     * Get count of serials pending Ikonic sync (including retries).
     *
     * @return int Number of pending serials
     */
    public function getUnsyncedSerialCount(): int
    {
        $serialsTable = $this->table('serials');
        $ordersTable = $this->table('orders');

        $sql = "SELECT COUNT(*)
                FROM {$serialsTable} s
                INNER JOIN {$ordersTable} o ON s.order_id = o.mintsoft_id
                WHERE s.synced_to_ikonic_at IS NULL
                  AND (
                      s.ikonic_sync_error IS NULL
                      OR s.ikonic_sync_error_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                  )
                  AND o.tracking_number IS NOT NULL
                  AND o.tracking_number != ''
                  AND o.external_order_reference IS NOT NULL
                  AND o.external_order_reference != ''";

        $stmt = $this->pdo->query($sql);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get count of serials with recent Ikonic sync errors (within 1 hour).
     *
     * @return int Number of failed serials
     */
    public function getIkonicErrorCount(): int
    {
        $table = $this->table('serials');
        $sql = "SELECT COUNT(*) FROM {$table}
                WHERE ikonic_sync_error IS NOT NULL
                  AND ikonic_sync_error_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $stmt = $this->pdo->query($sql);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get serials with Ikonic sync errors for review.
     *
     * @param int $limit Maximum number to return
     * @return array Array of error records
     */
    public function getIkonicErrorSerials(int $limit = 100): array
    {
        $serialsTable = $this->table('serials');
        $ordersTable = $this->table('orders');

        $sql = "SELECT
                    s.id AS serial_id,
                    s.serial_number,
                    s.sku,
                    s.ikonic_sync_error,
                    s.ikonic_sync_error_at,
                    o.order_number,
                    o.external_order_reference
                FROM {$serialsTable} s
                INNER JOIN {$ordersTable} o ON s.order_id = o.mintsoft_id
                WHERE s.ikonic_sync_error IS NOT NULL
                ORDER BY s.ikonic_sync_error_at DESC
                LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ============================================
    // SellerFlex Stock Sync Methods
    // ============================================

    /**
     * Start a new SellerFlex sync run.
     *
     * @return int The sync run ID
     */
    public function startSellerFlexSyncRun(): int
    {
        $table = $this->table('sellerflex_sync_runs');
        $sql = "INSERT INTO {$table} (started_at) VALUES (NOW())";
        $this->executeWithRetry($sql, []);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Complete a SellerFlex sync run with stats.
     *
     * @param int $runId The sync run ID
     * @param int $skusFound Number of SellerFlex SKUs found
     * @param int $skusWithFbaData Number of SKUs with FBA data
     * @param int $skusUpdated Number of SKUs updated in Mintsoft
     * @param int $skusUnchanged Number of SKUs unchanged
     * @param int $skusFailed Number of SKUs that failed
     * @param string|null $errorMessage Overall error message if failed
     */
    public function completeSellerFlexSyncRun(
        int $runId,
        int $skusFound,
        int $skusWithFbaData,
        int $skusUpdated,
        int $skusUnchanged,
        int $skusFailed,
        ?string $errorMessage = null
    ): void {
        $table = $this->table('sellerflex_sync_runs');
        $sql = "UPDATE {$table}
                SET completed_at = NOW(),
                    skus_found = :skus_found,
                    skus_with_fba_data = :skus_with_fba_data,
                    skus_updated = :skus_updated,
                    skus_unchanged = :skus_unchanged,
                    skus_failed = :skus_failed,
                    error_message = :error_message
                WHERE id = :id";

        $this->executeWithRetry($sql, [
            'id' => $runId,
            'skus_found' => $skusFound,
            'skus_with_fba_data' => $skusWithFbaData,
            'skus_updated' => $skusUpdated,
            'skus_unchanged' => $skusUnchanged,
            'skus_failed' => $skusFailed,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Upsert a SellerFlex SKU record.
     *
     * @param string $sku The SKU
     * @param int|null $mintsoftProductId Mintsoft product ID
     * @param string|null $productName Product name
     * @return void
     */
    public function upsertSellerFlexSku(string $sku, ?int $mintsoftProductId, ?string $productName): void
    {
        $table = $this->table('sellerflex_skus');
        $sql = "INSERT INTO {$table} (sku, mintsoft_product_id, product_name)
                VALUES (:sku, :mintsoft_product_id, :product_name)
                ON DUPLICATE KEY UPDATE
                    mintsoft_product_id = VALUES(mintsoft_product_id),
                    product_name = VALUES(product_name)";

        $this->executeWithRetry($sql, [
            'sku' => $sku,
            'mintsoft_product_id' => $mintsoftProductId,
            'product_name' => $productName,
        ]);
    }

    /**
     * Update SellerFlex SKU with stock levels after sync.
     *
     * @param string $sku The SKU
     * @param int|null $fbaQuantity FBA fulfillable quantity
     * @param int|null $mintsoftQuantity Mintsoft on-hand quantity before update
     */
    public function updateSellerFlexSkuStock(string $sku, ?int $fbaQuantity, ?int $mintsoftQuantity): void
    {
        $table = $this->table('sellerflex_skus');
        $sql = "UPDATE {$table}
                SET last_fba_quantity = :fba_qty,
                    last_mintsoft_quantity = :mintsoft_qty,
                    last_synced_at = NOW(),
                    last_sync_error = NULL,
                    last_sync_error_at = NULL
                WHERE sku = :sku";

        $this->executeWithRetry($sql, [
            'sku' => $sku,
            'fba_qty' => $fbaQuantity,
            'mintsoft_qty' => $mintsoftQuantity,
        ]);
    }

    /**
     * Record a sync error for a SellerFlex SKU.
     *
     * @param string $sku The SKU
     * @param string $error Error message
     */
    public function recordSellerFlexSkuError(string $sku, string $error): void
    {
        $table = $this->table('sellerflex_skus');
        $sql = "UPDATE {$table}
                SET last_sync_error = :error,
                    last_sync_error_at = NOW()
                WHERE sku = :sku";

        $this->executeWithRetry($sql, [
            'sku' => $sku,
            'error' => $error,
        ]);
    }

    /**
     * Get recent SellerFlex sync runs.
     *
     * @param int $limit Maximum number to return
     * @return array Array of sync run records
     */
    public function getRecentSellerFlexSyncRuns(int $limit = 10): array
    {
        $table = $this->table('sellerflex_sync_runs');
        $sql = "SELECT * FROM {$table} ORDER BY started_at DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
