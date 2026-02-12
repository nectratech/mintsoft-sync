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
 */
class IkonicClient
{
    private Client $httpClient;
    private string $apiUrl;
    private string $apiKey;

    public function __construct(array $config)
    {
        $this->apiUrl = rtrim($config['api_url'] ?? 'https://api.ikonic.com', '/');
        $this->apiKey = $config['api_key'] ?? '';

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
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
}
