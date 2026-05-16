<?php

namespace Grobinson3108\LaravelRagPipeline\Memory;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Working Memory Service
 *
 * Implements the "7±2" working memory pattern using Redis
 * - Fast access (< 10ms)
 * - Limited capacity (7 most recent items)
 * - TTL-based automatic eviction
 * - LRU eviction policy
 */
class WorkingMemoryService
{
    protected $redis;
    protected $ttl;
    protected $maxItems;

    public function __construct()
    {
        $this->redis = Redis::connection();
        $this->ttl = config('rag-pipeline.working_memory_ttl', 3600); // 1 hour default
        $this->maxItems = config('rag-pipeline.working_memory_max_items', 7); // 7±2 pattern
    }

    /**
     * Store an item in working memory
     *
     * @param string $botId
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl Custom TTL in seconds
     * @return bool
     */
    public function store(string $botId, string $key, $value, ?int $ttl = null): bool
    {
        try {
            $redisKey = $this->buildKey($botId, $key);
            $serialized = json_encode($value);

            $this->redis->setex($redisKey, $ttl ?? $this->ttl, $serialized);

            // Add to sorted set for LRU tracking
            $this->redis->zadd(
                $this->buildListKey($botId),
                time(),
                $key
            );

            // Enforce 7±2 limit
            $this->enforceLimit($botId);

            Log::debug("WorkingMemory: Stored", ['bot_id' => $botId, 'key' => $key]);

            return true;
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Store failed", [
                'bot_id' => $botId,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Retrieve an item from working memory
     *
     * @param string $botId
     * @param string $key
     * @return mixed|null
     */
    public function get(string $botId, string $key)
    {
        try {
            $redisKey = $this->buildKey($botId, $key);
            $value = $this->redis->get($redisKey);

            if ($value === null) {
                return null;
            }

            // Update access time (LRU)
            $this->redis->zadd(
                $this->buildListKey($botId),
                time(),
                $key
            );

            return json_decode($value, true);
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Get failed", [
                'bot_id' => $botId,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get all items in working memory for a bot
     *
     * @param string $botId
     * @return array
     */
    public function getAll(string $botId): array
    {
        try {
            $keys = $this->redis->zrange($this->buildListKey($botId), 0, -1);
            $items = [];

            foreach ($keys as $key) {
                $value = $this->get($botId, $key);
                if ($value !== null) {
                    $items[$key] = $value;
                }
            }

            return $items;
        } catch (\Exception $e) {
            Log::error("WorkingMemory: GetAll failed", [
                'bot_id' => $botId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Remove an item from working memory
     *
     * @param string $botId
     * @param string $key
     * @return bool
     */
    public function forget(string $botId, string $key): bool
    {
        try {
            $redisKey = $this->buildKey($botId, $key);
            $this->redis->del($redisKey);
            $this->redis->zrem($this->buildListKey($botId), $key);

            Log::debug("WorkingMemory: Forgot", ['bot_id' => $botId, 'key' => $key]);

            return true;
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Forget failed", [
                'bot_id' => $botId,
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all working memory for a bot
     *
     * @param string $botId
     * @return bool
     */
    public function clear(string $botId): bool
    {
        try {
            $keys = $this->redis->zrange($this->buildListKey($botId), 0, -1);

            foreach ($keys as $key) {
                $this->redis->del($this->buildKey($botId, $key));
            }

            $this->redis->del($this->buildListKey($botId));

            Log::info("WorkingMemory: Cleared", ['bot_id' => $botId]);

            return true;
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Clear failed", [
                'bot_id' => $botId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get count of items in working memory
     *
     * @param string $botId
     * @return int
     */
    public function count(string $botId): int
    {
        try {
            return $this->redis->zcard($this->buildListKey($botId));
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Count failed", [
                'bot_id' => $botId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Enforce 7±2 item limit (Working Memory pattern)
     * Removes least recently used items if limit exceeded
     *
     * @param string $botId
     * @return void
     */
    protected function enforceLimit(string $botId): void
    {
        try {
            $count = $this->count($botId);

            if ($count > $this->maxItems) {
                $toRemove = $count - $this->maxItems;

                // Get oldest items (lowest scores)
                $oldestKeys = $this->redis->zrange(
                    $this->buildListKey($botId),
                    0,
                    $toRemove - 1
                );

                foreach ($oldestKeys as $key) {
                    $this->forget($botId, $key);
                }

                Log::info("WorkingMemory: Enforced limit", [
                    'bot_id' => $botId,
                    'removed' => $toRemove,
                    'limit' => $this->maxItems
                ]);
            }
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Enforce limit failed", [
                'bot_id' => $botId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Build Redis key for bot-specific storage
     *
     * @param string $botId
     * @param string $key
     * @return string
     */
    protected function buildKey(string $botId, string $key): string
    {
        return "rag:working_memory:{$botId}:{$key}";
    }

    /**
     * Build Redis key for bot's item list
     *
     * @param string $botId
     * @return string
     */
    protected function buildListKey(string $botId): string
    {
        return "rag:working_memory:{$botId}:_list";
    }

    /**
     * Health check for Redis connection
     *
     * @return bool
     */
    public function healthCheck(): bool
    {
        try {
            $this->redis->ping();
            return true;
        } catch (\Exception $e) {
            Log::error("WorkingMemory: Health check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
