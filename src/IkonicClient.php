<?php

declare(strict_types=1);

namespace MintsoftSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Client for the Ikonic API.
 *
 * Used to fetch correct external order references that the native
 * Linnworks → Mintsoft integration sometimes gets wrong.
 * Also pushes serial numbers and tracking info to Ikonic 3PL.
 */
class IkonicClient
{
    private Client $httpClient;
    private string $apiUrl;
    private string $apiKey;
    private ?RateLimiter $rateLimiter;

    public function __construct(array $config, ?RateLimiter $rateLimiter = null)
    {
        $this->apiUrl = rtrim($config['api_url'] ?? 'https://api.ikonic.com', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->rateLimiter = $rateLimiter;

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey,
            ],
        ]);
    }

    /**
     * Get the correct external order ID from Ikonic.
     *
     * @param string $secondRef The secondary reference (current ExternalOrderReference from Mintsoft)
     * @return array ['success' => bool, 'external_order_id' => string|null, 'error' => string|null]
     */
    public function getExternalOrderId(string $secondRef): array
    {
        try {
            $response = $this->httpClient->get('/3pl/get-external-order-id', [
                'query' => ['second_ref' => $secondRef],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'external_order_id' => null,
                    'error' => 'Invalid JSON response from Ikonic',
                ];
            }

            $externalOrderId = $data['external_order_id'] ?? null;

            if (!$externalOrderId) {
                return [
                    'success' => false,
                    'external_order_id' => null,
                    'error' => 'Ikonic response missing external_order_id',
                ];
            }

            return [
                'success' => true,
                'external_order_id' => $externalOrderId,
                'error' => null,
            ];

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            return [
                'success' => false,
                'external_order_id' => null,
                'error' => "Ikonic API error (HTTP {$statusCode}): " . substr($responseBody, 0, 200),
                'status_code' => $statusCode,
            ];

        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'external_order_id' => null,
                'error' => 'Ikonic connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Update order with tracking and serial information.
     *
     * POST /3pl/update-order
     *
     * @param array $payload {
     *     @type string $OrderID          External order reference (e.g., "20250801116743")
     *     @type string $TrackingNumber   Courier tracking number
     *     @type string $SerialNumber     Product serial number
     *     @type string $SKU              Product SKU
     *     @type string $ReferenceNumber  Order reference/number
     * }
     * @return array ['success' => bool, 'error' => string|null, 'response' => array|null]
     */
    public function updateOrder(array $payload): array
    {
        // Apply rate limiting if configured
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->wait();
        }

        try {
            $response = $this->httpClient->post('/3pl/update-order', [
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $data = ['raw_response' => $body];
            }

            // Consider 2xx responses as success
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'error' => null,
                    'response' => $data,
                    'status_code' => $statusCode,
                ];
            }

            // Non-2xx but no exception (shouldn't happen with Guzzle defaults)
            return [
                'success' => false,
                'error' => "Unexpected status code: {$statusCode}",
                'response' => $data,
                'status_code' => $statusCode,
            ];

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $responseData = json_decode($responseBody, true);

            // Try to extract error message from response
            $errorMessage = $responseData['message']
                ?? $responseData['error']
                ?? $responseData['Message']
                ?? substr($responseBody, 0, 200);

            return [
                'success' => false,
                'error' => "Ikonic API error (HTTP {$statusCode}): {$errorMessage}",
                'response' => $responseData,
                'status_code' => $statusCode,
            ];

        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error' => 'Ikonic connection error: ' . $e->getMessage(),
                'response' => null,
                'status_code' => 0,
            ];
        }
    }

    /**
     * Get FBA fulfillable quantities in bulk for multiple SKUs.
     *
     * POST /3pl/get-fba-fulfillable-quantities/bulk
     *
     * @param array $skus Array of SKU strings
     * @return array ['success' => bool, 'quantities' => array|null, 'error' => string|null]
     *               quantities is keyed by SKU: ['SKU123' => 50, 'SKU456' => 0, ...]
     */
    public function getFbaQuantitiesBulk(array $skus): array
    {
        // Apply rate limiting if configured
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->wait();
        }

        try {
            $response = $this->httpClient->post('/3pl/get-fba-fulfillable-quantities/bulk', [
                'json' => ['skus' => $skus],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'quantities' => null,
                    'error' => 'Invalid JSON response from Ikonic',
                    'raw_response' => substr($body, 0, 500),
                ];
            }

            // Consider 2xx responses as success
            if ($statusCode >= 200 && $statusCode < 300) {
                // Extract quantities - expected format: { "quantities": { "SKU1": 50, "SKU2": 0 } }
                $quantities = $data['quantities'] ?? $data;

                return [
                    'success' => true,
                    'quantities' => $quantities,
                    'error' => null,
                    'status_code' => $statusCode,
                ];
            }

            return [
                'success' => false,
                'quantities' => null,
                'error' => "Unexpected status code: {$statusCode}",
                'status_code' => $statusCode,
            ];

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $responseData = json_decode($responseBody, true);

            $errorMessage = $responseData['message']
                ?? $responseData['error']
                ?? $responseData['Message']
                ?? substr($responseBody, 0, 200);

            return [
                'success' => false,
                'quantities' => null,
                'error' => "Ikonic API error (HTTP {$statusCode}): {$errorMessage}",
                'status_code' => $statusCode,
            ];

        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'quantities' => null,
                'error' => 'Ikonic connection error: ' . $e->getMessage(),
                'status_code' => 0,
            ];
        }
    }
}
