<?php

declare(strict_types=1);

namespace MintsoftSync;

use Predis\Client as RedisClient;

class Queue
{
    private RedisClient $redis;
    private string $prefix;

    // Queue names
    public const QUEUE_ORDERS = 'queue:orders';
    public const QUEUE_SERIALS = 'queue:serials';
    public const QUEUE_FAILED = 'queue:failed';

    public function __construct(RedisClient $redis, string $prefix = 'mintsoft:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * Push a job to a queue.
     */
    public function push(string $queue, array $payload): void
    {
        $job = json_encode([
            'payload' => $payload,
            'attempts' => 0,
            'created_at' => date('c'),
        ]);

        $this->redis->rpush($this->prefix . $queue, [$job]);
    }

    /**
     * Pop a job from a queue (blocking).
     * 
     * @param string $queue Queue name
     * @param int $timeout Timeout in seconds (0 = block forever)
     * @return array|null
     */
    public function pop(string $queue, int $timeout = 5): ?array
    {
        $result = $this->redis->blpop([$this->prefix . $queue], $timeout);

        if ($result === null) {
            return null;
        }

        // blpop returns [key, value]
        $job = json_decode($result[1], true);
        
        if ($job === null) {
            return null;
        }

        $job['attempts']++;
        return $job;
    }

    /**
     * Push a failed job to the failed queue.
     */
    public function fail(string $originalQueue, array $job, string $error): void
    {
        $failedJob = json_encode([
            'original_queue' => $originalQueue,
            'payload' => $job['payload'],
            'attempts' => $job['attempts'],
            'error' => $error,
            'failed_at' => date('c'),
        ]);

        $this->redis->rpush($this->prefix . self::QUEUE_FAILED, [$failedJob]);
    }

    /**
     * Retry a job (push back to queue with delay).
     */
    public function retry(string $queue, array $job, int $delaySeconds = 0): void
    {
        if ($delaySeconds > 0) {
            // For delayed retry, use a sorted set
            $score = time() + $delaySeconds;
            $this->redis->zadd(
                $this->prefix . $queue . ':delayed',
                [json_encode($job) => $score]
            );
        } else {
            // Immediate retry - push back to queue
            $this->redis->rpush($this->prefix . $queue, [json_encode($job)]);
        }
    }

    /**
     * Get queue length.
     */
    public function length(string $queue): int
    {
        return $this->redis->llen($this->prefix . $queue);
    }

    /**
     * Clear a queue (useful for testing).
     */
    public function clear(string $queue): void
    {
        $this->redis->del($this->prefix . $queue);
    }

    /**
     * Get all failed jobs.
     */
    public function getFailedJobs(int $limit = 100): array
    {
        $jobs = $this->redis->lrange($this->prefix . self::QUEUE_FAILED, 0, $limit - 1);
        return array_map(fn($job) => json_decode($job, true), $jobs);
    }

    /**
     * Clear failed jobs.
     */
    public function clearFailed(): int
    {
        $count = $this->length(self::QUEUE_FAILED);
        $this->clear(self::QUEUE_FAILED);
        return $count;
    }
}