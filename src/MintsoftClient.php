<?php

declare(strict_types=1);

namespace MintsoftSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class MintsoftClient
{
    private Client $httpClient;
    private string $apiUrl;
    private string $apiKey;
    private int $clientId;
    private RateLimiter $rateLimiter;
    private ErrorLogger $logger;

    public function __construct(
        array $config,
        RateLimiter $rateLimiter,
        ErrorLogger $logger
    ) {
        $this->apiUrl = rtrim($config['api_url'], '/');
        $this->apiKey = $config['api_key'];
        $this->clientId = $config['client_id'];
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;

        $this->httpClient = new Client([
            'base_uri' => $this->apiUrl,
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Get orders for the configured client, optionally filtered by date.
     * Handles pagination automatically to fetch all results.
     *
     * @param \DateTime|null $modifiedSince Only return orders modified after this date
     * @param int $limit Number of orders per page (max 100)
     * @return array
     */
    public function getOrders(?\DateTime $modifiedSince = null, int $limit = 100): array
    {
        $allOrders = [];
        $pageNo = 1;

        do {
            $params = [
                'APIKey' => $this->apiKey,
                'ClientId' => $this->clientId,
                'PageNo' => $pageNo,
                'Limit' => $limit,
                'IncludeOrderItems' => true,
            ];

            // If we have a modified since date, add it
            if ($modifiedSince) {
                $params['SinceLastUpdated'] = $modifiedSince->format('Y-m-d\TH:i:s');
            }

            $response = $this->request('GET', '/api/Order/List', $params);

            // Handle different response formats
            // Some APIs return { Items: [...], TotalCount: N }
            // Others return just the array directly
            $orders = [];
            if (isset($response['Items']) && is_array($response['Items'])) {
                $orders = $response['Items'];
            } elseif (isset($response['Data']) && is_array($response['Data'])) {
                $orders = $response['Data'];
            } elseif (is_array($response) && !isset($response['Items']) && !isset($response['Data'])) {
                // Response is the array itself
                $orders = $response;
            }

            if (empty($orders)) {
                break;
            }

            $allOrders = array_merge($allOrders, $orders);
            $pageNo++;

            // Safety check - if we got less than limit, we've reached the end
            if (count($orders) < $limit) {
                break;
            }

        } while (true);

        return $allOrders;
    }

    /**
     * Get a single order by ID.
     */
    public function getOrder(int $orderId): array
    {
        return $this->request('GET', "/api/Order/{$orderId}", [
            'APIKey' => $this->apiKey,
        ]);
    }

    /**
     * Get barcode verified order items (serials) for an order.
     */
    public function getBarcodeVerifiedOrderItems(int $orderId): array
    {
        return $this->request('GET', "/api/Order/{$orderId}/BarcodeVerifiedOrderItems", [
            'APIKey' => $this->apiKey,
        ]);
    }

    /**
     * Get order items (lines) for an order.
     */
    public function getOrderItems(int $orderId): array
    {
        return $this->request('GET', "/api/Order/{$orderId}/Items", [
            'APIKey' => $this->apiKey,
        ]);
    }

    /**
     * Update an order's ExternalOrderReference.
     *
     * Returns the full API response including Success flag and any error messages.
     * Does not throw on API errors - caller should check 'Success' field.
     *
     * @param int $orderId The Mintsoft order ID
     * @param string $externalOrderReference The new external order reference
     * @return array The API response with Success, Message, etc.
     */
    public function updateExternalOrderReference(int $orderId, string $externalOrderReference): array
    {
        $this->rateLimiter->wait();

        try {
            $response = $this->httpClient->post("/api/Order/{$orderId}", [
                'query' => ['APIKey' => $this->apiKey],
                'json' => ['ExternalOrderReference' => $externalOrderReference],
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'Success' => false,
                    'Message' => 'Invalid JSON response: ' . json_last_error_msg(),
                    'RawResponse' => substr($body, 0, 500),
                ];
            }

            return $data ?? ['Success' => true];

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            // Try to parse the response body as JSON (Mintsoft returns structured errors)
            $errorData = json_decode($responseBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($errorData)) {
                return $errorData;
            }

            return [
                'Success' => false,
                'Message' => "HTTP {$statusCode}: " . $e->getMessage(),
                'StatusCode' => $statusCode,
                'RawResponse' => substr($responseBody, 0, 500),
            ];

        } catch (GuzzleException $e) {
            return [
                'Success' => false,
                'Message' => 'Connection error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Make an API request with rate limiting and error handling.
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        // Wait for rate limit
        $this->rateLimiter->wait();

        $options = [];
        if ($method === 'GET') {
            $options['query'] = $params;
        } else {
            $options['json'] = $params;
        }

        try {
            $response = $this->httpClient->request($method, $endpoint, $options);
            $body = (string) $response->getBody();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
            }

            return $data ?? [];

        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';

            $this->logger->error('Mintsoft API request failed', [
                'endpoint' => $endpoint,
                'method' => $method,
                'status_code' => $statusCode,
                'response_body' => substr($responseBody, 0, 1000),
                'exception' => $e->getMessage(),
            ]);

            // Handle rate limiting - 429 Too Many Requests
            if ($statusCode === 429) {
                $this->logger->warning('Rate limited by Mintsoft API, backing off', [
                    'endpoint' => $endpoint,
                ]);
                // Sleep for 60 seconds and retry once
                sleep(60);
                return $this->request($method, $endpoint, $params);
            }

            throw new \RuntimeException(
                "Mintsoft API error ({$statusCode}): " . $e->getMessage(),
                $statusCode,
                $e
            );

        } catch (GuzzleException $e) {
            $this->logger->error('Mintsoft API connection error', [
                'endpoint' => $endpoint,
                'method' => $method,
                'exception' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                'Mintsoft API connection error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}