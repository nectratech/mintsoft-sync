<?php

declare(strict_types=1);

namespace MintsoftSync;

use PDO;

/**
 * Validates serials from Mintsoft against the VL serial numbers database.
 * Sends email notifications if validation fails.
 */
class SerialValidator
{
    private PDO $pdo;
    private array $dbConfig;
    private ErrorLogger $logger;
    private string $notificationEmail;
    private string $fromEmail;

    public function __construct(
        array $dbConfig,
        ErrorLogger $logger,
        string $notificationEmail = 'ttp@lamafulfiment.com',
        string $fromEmail = 'support@virtuallogistics.co.uk'
    ) {
        $this->dbConfig = $dbConfig;
        $this->logger = $logger;
        $this->notificationEmail = $notificationEmail;
        $this->fromEmail = $fromEmail;
        $this->connect();
    }

    /**
     * Establish database connection.
     */
    private function connect(): void
    {
        // Connect to VL database for serial validation
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            'vl' // VL database for serialNumbers table
        );

        $this->pdo = new PDO($dsn, $this->dbConfig['username'], $this->dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * Check if connection is alive and reconnect if needed.
     */
    public function ensureConnected(): void
    {
        try {
            $this->pdo->getAttribute(PDO::ATTR_SERVER_INFO);
        } catch (\PDOException $e) {
            $this->connect();
        }
    }

    /**
     * Execute a prepared statement with automatic retry on connection errors.
     */
    private function executeWithRetry(string $sql, array $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'gone away') !== false ||
                strpos($e->getMessage(), 'Lost connection') !== false ||
                $e->getCode() === 'HY000') {

                $this->connect();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    /**
     * Validate a serial number from Mintsoft.
     *
     * @param string $serialNumber The serial number to validate
     * @param string $sku The SKU (with or without IKO- prefix)
     * @param string $orderReference The order reference (e.g., Amazon order ID)
     * @param string $mintsoftOrderNumber The Mintsoft order number
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateSerial(
        string $serialNumber,
        string $sku,
        string $orderReference,
        string $mintsoftOrderNumber
    ): array {
        // Normalize SKU - ensure it has IKO- prefix for VL lookup
        $vlSku = $this->normalizeSkuForVL($sku);

        // Check if serial exists in VL database (query by serial only - serials are unique)
        $stmt = $this->executeWithRetry(
            "SELECT status, SKU FROM serialNumbers WHERE serial = ?",
            [$serialNumber]
        );
        $row = $stmt->fetch();

        if (!$row) {
            // Serial doesn't exist in VL database
            $this->sendEmailSerialDoesNotExist($serialNumber, $vlSku, $orderReference, $mintsoftOrderNumber);
            return [
                'valid' => false,
                'error' => "Serial {$serialNumber} does not exist in HQ database for SKU {$vlSku}"
            ];
        }

        $currentStatus = $row['status'];

        // Check if serial is unallocated or already allocated to this order
        if ($currentStatus !== 'Unallocated' && $currentStatus !== $orderReference) {
            // Serial is already allocated to a different order
            $this->sendEmailSerialAlreadyUsed($serialNumber, $orderReference, $currentStatus, $mintsoftOrderNumber);
            return [
                'valid' => false,
                'error' => "Serial {$serialNumber} already allocated to order {$currentStatus}"
            ];
        }

        // Serial is valid - allocate it to this order
        if ($currentStatus === 'Unallocated') {
            $this->allocateSerial($serialNumber, $orderReference);
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Allocate a serial to an order in VL database.
     */
    private function allocateSerial(string $serialNumber, string $orderReference): void
    {
        $this->executeWithRetry(
            "UPDATE serialNumbers SET status = ? WHERE serial = ?",
            [$orderReference, $serialNumber]
        );

        $this->logger->info("Allocated serial {$serialNumber} to order {$orderReference}");
    }

    /**
     * Normalize SKU to VL format (with IKO- prefix).
     */
    private function normalizeSkuForVL(string $sku): string
    {
        // If SKU doesn't have IKO- prefix, add it
        if (strpos($sku, 'IKO-') !== 0) {
            return 'IKO-' . $sku;
        }
        return $sku;
    }

    /**
     * Send email when serial doesn't exist in database.
     */
    private function sendEmailSerialDoesNotExist(
        string $serialNumber,
        string $sku,
        string $orderReference,
        string $mintsoftOrderNumber
    ): void {
        $subject = "Mintsoft serial number does not exist - {$serialNumber}";
        $body = "Serial number {$serialNumber} (SKU: {$sku}) does not exist in HQ database.\n\n"
            . "Mintsoft Order: {$mintsoftOrderNumber}\n"
            . "Channel Order Ref: {$orderReference}\n\n"
            . "This serial was captured via barcode scanning in Mintsoft but is not registered in the VL system.";

        $this->logger->warning("Serial does not exist", [
            'serial' => $serialNumber,
            'sku' => $sku,
            'order_reference' => $orderReference,
            'mintsoft_order' => $mintsoftOrderNumber,
        ]);

        $this->sendEmail($subject, $body);
    }

    /**
     * Send email when serial is already allocated to another order.
     */
    private function sendEmailSerialAlreadyUsed(
        string $serialNumber,
        string $newOrderReference,
        string $existingOrderReference,
        string $mintsoftOrderNumber
    ): void {
        $subject = "Duplicate Mintsoft serial number - {$serialNumber}";
        $body = "Serial number {$serialNumber} is already allocated to order {$existingOrderReference}.\n\n"
            . "Attempted to allocate to:\n"
            . "  Mintsoft Order: {$mintsoftOrderNumber}\n"
            . "  Channel Order Ref: {$newOrderReference}\n\n"
            . "Currently allocated to: {$existingOrderReference}\n\n"
            . "This may indicate a duplicate scan or system error.";

        $this->logger->warning("Serial already allocated", [
            'serial' => $serialNumber,
            'new_order' => $newOrderReference,
            'existing_order' => $existingOrderReference,
            'mintsoft_order' => $mintsoftOrderNumber,
        ]);

        $this->sendEmail($subject, $body);
    }

    /**
     * Send email notification.
     */
    private function sendEmail(string $subject, string $body): void
    {
        try {
            // Try to use the main mailer class if available
            if (class_exists('mailer')) {
                $mailer = new \mailer();
                $mailer->sendMail(
                    $this->notificationEmail,
                    $this->fromEmail,
                    $subject,
                    $body,
                    false, // plain text
                    null,
                    null
                );
                return;
            }

            // Fallback to PHP mail()
            $headers = [
                'From' => $this->fromEmail,
                'Reply-To' => $this->fromEmail,
                'X-Mailer' => 'MintsoftSync/1.0',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ];

            $headerString = '';
            foreach ($headers as $key => $value) {
                $headerString .= "{$key}: {$value}\r\n";
            }

            $sent = mail($this->notificationEmail, $subject, $body, $headerString);

            if (!$sent) {
                $this->logger->error("Failed to send email", [
                    'to' => $this->notificationEmail,
                    'subject' => $subject,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Exception sending email: " . $e->getMessage(), [
                'to' => $this->notificationEmail,
                'subject' => $subject,
            ]);
        }
    }
}
