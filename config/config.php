<?php

declare(strict_types=1);

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

return [
    'mintsoft' => [
        'api_url' => $_ENV['MINTSOFT_API_URL'] ?? 'https://api.mintsoft.co.uk',
        'api_key' => $_ENV['MINTSOFT_API_KEY'],
        'client_id' => (int) ($_ENV['MINTSOFT_CLIENT_ID'] ?? 10),
    ],
    
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'database' => $_ENV['DB_DATABASE'] ?? 'mintsoft_sync',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'table_prefix' => $_ENV['DB_TABLE_PREFIX'] ?? '',
    ],
    
    'redis' => [
        'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
        'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
        'password' => $_ENV['REDIS_PASSWORD'] ?: null,
        'prefix' => $_ENV['REDIS_PREFIX'] ?? 'mintsoft:',
    ],
    
    'error_hub' => [
        'url' => $_ENV['ERROR_HUB_URL'] ?? 'https://errors-api.nectra.co.uk/api/ingest',
        'token' => $_ENV['ERROR_HUB_TOKEN'],
        'source' => $_ENV['ERROR_HUB_SOURCE'] ?? 'mintsoft-sync',
    ],
    
    'rate_limit' => [
        'requests' => (int) ($_ENV['RATE_LIMIT_REQUESTS'] ?? 2),
        'per_seconds' => (int) ($_ENV['RATE_LIMIT_PER_SECONDS'] ?? 1),
    ],
    
    'sync' => [
        'lookback_minutes' => (int) ($_ENV['SYNC_LOOKBACK_MINUTES'] ?? 30),
    ],

    'ikonic' => [
        'api_url' => $_ENV['IKONIC_API_URL'] ?? 'https://api.ikonic.com',
        'api_key' => $_ENV['IKONIC_API_KEY'] ?? '',
    ],

    'mail' => [
        'host' => $_ENV['MAIL_HOST'] ?? 'localhost',
        'port' => $_ENV['MAIL_PORT'] ?? 587,
        'username' => $_ENV['MAIL_USERNAME'] ?? '',
        'password' => $_ENV['MAIL_PASSWORD'] ?? '',
        'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
        'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@example.com',
        'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'Mintsoft Sync',
    ],
];