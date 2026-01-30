<?php

declare(strict_types=1);

namespace MintsoftSync;

use Predis\Client as RedisClient;

class RateLimiter
{
    private RedisClient $redis;
    private string $key;
    private int $maxRequests;
    private int $perSeconds;

    public function __construct(RedisClient $redis, string $key, int $maxRequests, int $perSeconds)
    {
        $this->redis = $redis;
        $this->key = $key;
        $this->maxRequests = $maxRequests;
        $this->perSeconds = $perSeconds;
    }

    /**
     * Wait until we can make a request, then consume a token.
     * Uses a sliding window approach stored in Redis.
     */
    public function wait(): void
    {
        while (!$this->tryAcquire()) {
            // Sleep for 100ms and try again
            usleep(100000);
        }
    }

    /**
     * Try to acquire a rate limit token.
     * Returns true if acquired, false if we need to wait.
     */
    public function tryAcquire(): bool
    {
        $now = microtime(true);
        $windowStart = $now - $this->perSeconds;
        
        // Use Redis transaction for atomicity
        $this->redis->multi();
        
        // Remove old entries outside the window
        $this->redis->zremrangebyscore($this->key, '-inf', (string) $windowStart);
        
        // Count current entries in window
        $this->redis->zcard($this->key);
        
        // Execute and get results
        $results = $this->redis->exec();
        $currentCount = $results[1] ?? 0;
        
        if ($currentCount < $this->maxRequests) {
            // Add new entry with current timestamp as score
            $this->redis->zadd($this->key, [$now . ':' . uniqid() => $now]);
            
            // Set expiry on the key to auto-cleanup
            $this->redis->expire($this->key, $this->perSeconds + 1);
            
            return true;
        }
        
        return false;
    }

    /**
     * Get current usage stats.
     */
    public function getStats(): array
    {
        $now = microtime(true);
        $windowStart = $now - $this->perSeconds;
        
        // Clean old entries first
        $this->redis->zremrangebyscore($this->key, '-inf', (string) $windowStart);
        
        $currentCount = $this->redis->zcard($this->key);
        
        return [
            'current' => $currentCount,
            'max' => $this->maxRequests,
            'window_seconds' => $this->perSeconds,
            'available' => max(0, $this->maxRequests - $currentCount),
        ];
    }

    /**
     * Reset the rate limiter (useful for testing).
     */
    public function reset(): void
    {
        $this->redis->del($this->key);
    }
}
