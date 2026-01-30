<?php

declare(strict_types=1);

namespace MintsoftSync;

/**
 * Maps Mintsoft API responses to our database schema.
 * 
 * This centralises all the field mapping so if Mintsoft changes their API
 * or we need to adjust our schema, we only change it here.
 */
class OrderMapper
{
    /**
     * Map a Mintsoft order to our database schema.
     */
    public function mapOrder(array $mintsoftOrder): array
    {
        return [
            'mintsoft_id' => $mintsoftOrder['ID'] ?? $mintsoftOrder['Id'] ?? null,
            'order_number' => $mintsoftOrder['OrderNumber'] ?? null,
            'external_order_reference' => $mintsoftOrder['ExternalOrderReference'] ?? $mintsoftOrder['ExternalReference'] ?? null,
            'client_id' => $mintsoftOrder['ClientId'] ?? null,
            'status' => $mintsoftOrder['OrderStatus'] ?? $mintsoftOrder['Status'] ?? null,
            'channel' => $this->extractNestedValue($mintsoftOrder, 'Channel', 'Name', 'ChannelName'),
            'currency' => $this->extractNestedValue($mintsoftOrder, 'Currency', 'Code', 'CurrencyCode'),
            'total_amount' => $mintsoftOrder['TotalAmount'] ?? $mintsoftOrder['OrderTotal'] ?? null,
            'order_value' => $mintsoftOrder['OrderValue'] ?? $mintsoftOrder['TotalOrderGross'] ?? null,
            'shipping_method' => $mintsoftOrder['ShippingMethod'] ?? $mintsoftOrder['CourierName'] ?? null,

            // Tracking info
            'tracking_number' => $mintsoftOrder['TrackingNumber'] ?? $mintsoftOrder['ConsignmentNumber'] ?? null,
            'tracking_url' => $mintsoftOrder['TrackingURL'] ?? $mintsoftOrder['TrackingUrl'] ?? null,
            'courier_service_name' => $mintsoftOrder['CourierServiceName'] ?? $mintsoftOrder['CourierService'] ?? null,

            // Order totals
            'total_items' => $mintsoftOrder['TotalItems'] ?? $mintsoftOrder['ItemCount'] ?? null,
            'total_weight' => $mintsoftOrder['TotalWeight'] ?? $mintsoftOrder['Weight'] ?? null,
            'warehouse_id' => $mintsoftOrder['WarehouseId'] ?? $mintsoftOrder['WarehouseID'] ?? null,

            // Customer details
            'customer_name' => $this->buildCustomerName($mintsoftOrder),
            'customer_email' => $mintsoftOrder['Email'] ?? $mintsoftOrder['CustomerEmail'] ?? null,
            'customer_phone' => $mintsoftOrder['Phone'] ?? $mintsoftOrder['Telephone'] ?? null,

            // Shipping address
            'shipping_address_1' => $mintsoftOrder['DeliveryAddress1'] ?? $mintsoftOrder['Address1'] ?? null,
            'shipping_address_2' => $mintsoftOrder['DeliveryAddress2'] ?? $mintsoftOrder['Address2'] ?? null,
            'shipping_city' => $mintsoftOrder['DeliveryTown'] ?? $mintsoftOrder['Town'] ?? null,
            'shipping_county' => $mintsoftOrder['DeliveryCounty'] ?? $mintsoftOrder['County'] ?? null,
            'shipping_postcode' => $mintsoftOrder['DeliveryPostCode'] ?? $mintsoftOrder['PostCode'] ?? null,
            'shipping_country' => $this->extractNestedValue($mintsoftOrder, 'Country', 'Name', 'DeliveryCountry', 'CountryName'),

            // Additional info
            'comments' => $mintsoftOrder['Comments'] ?? $mintsoftOrder['OrderComments'] ?? null,
            'gift_messages' => $mintsoftOrder['GiftMessages'] ?? $mintsoftOrder['GiftMessage'] ?? null,
            'tags' => $this->extractTags($mintsoftOrder),

            // Dates
            'order_date' => $this->parseDate($mintsoftOrder['OrderDate'] ?? $mintsoftOrder['DateCreated'] ?? null),
            'despatch_date' => $this->parseDate($mintsoftOrder['DespatchDate'] ?? null),
        ];
    }

    /**
     * Extract a value from a potentially nested object.
     * Handles: { Channel: { Name: "Amazon" } } or { ChannelName: "Amazon" } or { Channel: "Amazon" }
     */
    private function extractNestedValue(array $data, string $objectKey, string $property, string ...$fallbackKeys): ?string
    {
        // Check if it's a nested object
        if (isset($data[$objectKey]) && is_array($data[$objectKey])) {
            // Try common property names: Name, Code, ID
            return $data[$objectKey][$property]
                ?? $data[$objectKey]['Name']
                ?? $data[$objectKey]['Code']
                ?? $data[$objectKey]['ID']
                ?? null;
        }

        // Check if it's already a scalar value
        if (isset($data[$objectKey]) && is_scalar($data[$objectKey])) {
            return (string) $data[$objectKey];
        }

        // Try fallback keys
        foreach ($fallbackKeys as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return (string) $data[$key];
            }
        }

        return null;
    }

    /**
     * Extract tags from order - may be array or comma-separated string.
     */
    private function extractTags(array $order): ?string
    {
        $tags = $order['Tags'] ?? $order['OrderTags'] ?? null;

        if ($tags === null) {
            return null;
        }

        if (is_array($tags)) {
            // Extract names if it's an array of objects
            $tagNames = array_map(function ($tag) {
                if (is_array($tag)) {
                    return $tag['Name'] ?? $tag['TagName'] ?? '';
                }
                return (string) $tag;
            }, $tags);
            return implode(',', array_filter($tagNames));
        }

        return (string) $tags;
    }

    /**
     * Map a Mintsoft order item to our order_lines schema.
     */
    public function mapOrderLine(array $mintsoftItem): array
    {
        return [
            'mintsoft_line_id' => $mintsoftItem['ID'] ?? $mintsoftItem['Id'] ?? null,
            'product_id' => $mintsoftItem['ProductId'] ?? null,
            'sku' => $mintsoftItem['SKU'] ?? $mintsoftItem['Sku'] ?? null,
            'product_name' => $mintsoftItem['Name'] ?? $mintsoftItem['ProductName'] ?? null,
            'quantity' => $mintsoftItem['Quantity'] ?? $mintsoftItem['Qty'] ?? 0,
            'unit_price' => $mintsoftItem['UnitPrice'] ?? $mintsoftItem['Price'] ?? null,
            'line_total' => $mintsoftItem['LineTotal'] ?? $mintsoftItem['Total'] ?? null,
        ];
    }

    /**
     * Map a Mintsoft barcode verified item to our serials schema.
     */
    public function mapSerial(array $mintsoftSerial, ?int $orderLineId = null): array
    {
        return [
            'order_line_id' => $orderLineId,
            'serial_number' => $mintsoftSerial['SerialNumber'] ?? $mintsoftSerial['Serial'] ?? null,
            'barcode' => $mintsoftSerial['Barcode'] ?? $mintsoftSerial['ItemBarcode'] ?? null,
            'product_id' => $mintsoftSerial['ProductId'] ?? null,
            'sku' => $mintsoftSerial['SKU'] ?? $mintsoftSerial['Sku'] ?? null,
            'verified_at' => $this->parseDate($mintsoftSerial['VerifiedDate'] ?? $mintsoftSerial['DateVerified'] ?? null),
        ];
    }

    /**
     * Build full customer name from parts.
     */
    private function buildCustomerName(array $order): ?string
    {
        // Try combined name first
        if (!empty($order['CustomerName'])) {
            return $order['CustomerName'];
        }
        
        if (!empty($order['DeliveryName'])) {
            return $order['DeliveryName'];
        }

        // Build from parts
        $parts = array_filter([
            $order['Title'] ?? $order['DeliveryTitle'] ?? '',
            $order['FirstName'] ?? $order['DeliveryFirstName'] ?? '',
            $order['LastName'] ?? $order['DeliveryLastName'] ?? $order['Surname'] ?? '',
        ]);

        return !empty($parts) ? implode(' ', $parts) : null;
    }

    /**
     * Parse various date formats from Mintsoft.
     */
    private function parseDate(?string $dateString): ?string
    {
        if (empty($dateString)) {
            return null;
        }

        // Handle .NET JSON date format: /Date(1234567890000)/
        if (preg_match('/\/Date\((\d+)/', $dateString, $matches)) {
            $timestamp = (int) ($matches[1] / 1000);
            return date('Y-m-d H:i:s', $timestamp);
        }

        // Try standard parsing
        try {
            $date = new \DateTime($dateString);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}