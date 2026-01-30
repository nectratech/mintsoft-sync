<?php

declare(strict_types=1);

namespace MintsoftSync;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;

class ErrorLogger
{
    private Client $httpClient;
    private string $url;
    private string $token;
    private string $source;
    private ?Logger $localLogger;

    public function __construct(array $config, ?Logger $localLogger = null)
    {
        $this->url = $config['url'];
        $this->token = $config['token'];
        $this->source = $config['source'];
        $this->localLogger = $localLogger;
        
        $this->httpClient = new Client([
            'timeout' => 5,
            'connect_timeout' => 5,
        ]);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        // Always log locally first
        if ($this->localLogger !== null) {
            $this->localLogger->log($this->mapLevel($level), $message, $context);
        }

        // Send to remote error hub
        $payload = [
            'source' => $this->source,
            'level' => strtoupper($level),
            'message' => $message,
            'context' => array_merge($context, [
                'timestamp' => date('c'),
                'hostname' => gethostname(),
                'php_version' => PHP_VERSION,
            ]),
        ];

        try {
            $this->httpClient->post($this->url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-ErrorHub-Token' => $this->token,
                ],
                'json' => $payload,
            ]);
        } catch (GuzzleException $e) {
            // Log locally if remote fails - don't throw, we don't want logging to break the app
            if ($this->localLogger !== null) {
                $this->localLogger->error('Failed to send to ErrorHub: ' . $e->getMessage(), [
                    'original_message' => $message,
                    'original_level' => $level,
                ]);
            }
        }
    }

    public function logException(\Throwable $e, array $context = []): void
    {
        $this->error($e->getMessage(), array_merge($context, [
            'exception_class' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]));
    }

    private function mapLevel(string $level): int
    {
        switch (strtoupper($level)) {
            case 'EMERGENCY':
                return Logger::EMERGENCY;
            case 'ALERT':
                return Logger::ALERT;
            case 'CRITICAL':
                return Logger::CRITICAL;
            case 'ERROR':
                return Logger::ERROR;
            case 'WARNING':
                return Logger::WARNING;
            case 'NOTICE':
                return Logger::NOTICE;
            case 'INFO':
                return Logger::INFO;
            case 'DEBUG':
                return Logger::DEBUG;
            default:
                return Logger::INFO;
        }
    }
}