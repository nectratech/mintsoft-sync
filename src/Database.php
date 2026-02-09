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
}
