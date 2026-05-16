<?php

namespace Grobinson3108\LaravelRagPipeline\Cache;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Grobinson3108\LaravelRagPipeline\RAG\EmbeddingService;

/**
 * QueryCacheService
 *
 * Cache intelligent multi-niveaux pour requêtes RAG:
 * - L1: In-memory (array statique) <3ms
 * - L2: Redis <10ms
 * - Similarity matching via embeddings
 *
 * Objectif: 80%+ hit rate, <3ms L1, <10ms L2
 */
class QueryCacheService
{
    private const L1_MAX_SIZE = 100; // Limite L1 cache (mémoire)
    private const L2_TTL = 1800; // 30 minutes (Redis)
    private const SIMILARITY_THRESHOLD = 0.92; // Cosine similarity threshold

    private static array $l1Cache = []; // Cache L1 in-memory
    private static int $l1Hits = 0;
    private static int $l2Hits = 0;
    private static int $misses = 0;

    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Get cached response for query
     *
     * Flow:
     * 1. Check L1 cache (in-memory)
     * 2. Check L2 cache (Redis)
     * 3. Check similar queries (embedding similarity)
     * 4. Return null if miss
     *
     * @param string $query La query utilisateur
     * @param string $botId ID du bot
     * @return array|null Cached response ou null
     */
    public function get(string $query, string $botId): ?array
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey($query, $botId);

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // L1: In-Memory Cache (ultra-rapide)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        if (isset(self::$l1Cache[$cacheKey])) {
            self::$l1Hits++;
            $duration = (microtime(true) - $startTime) * 1000;

            Log::debug('Cache L1 HIT', [
                'query' => substr($query, 0, 50),
                'duration_ms' => round($duration, 2),
            ]);

            return self::$l1Cache[$cacheKey];
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // L2: Redis Cache
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $cached = Redis::get($cacheKey);
        if ($cached) {
            $response = json_decode($cached, true);

            // Populate L1 cache
            $this->setL1($cacheKey, $response);

            self::$l2Hits++;
            $duration = (microtime(true) - $startTime) * 1000;

            Log::debug('Cache L2 HIT', [
                'query' => substr($query, 0, 50),
                'duration_ms' => round($duration, 2),
            ]);

            return $response;
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // L3: Similarity Search (queries similaires)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $similar = $this->getSimilar($query, $botId);
        if ($similar) {
            // Found similar cached query
            self::$l2Hits++;
            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('Cache SIMILAR HIT', [
                'query' => substr($query, 0, 50),
                'similar_query' => substr($similar['query'], 0, 50),
                'similarity' => $similar['similarity'],
                'duration_ms' => round($duration, 2),
            ]);

            return $similar['response'];
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // MISS
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        self::$misses++;
        $duration = (microtime(true) - $startTime) * 1000;

        Log::debug('Cache MISS', [
            'query' => substr($query, 0, 50),
            'duration_ms' => round($duration, 2),
        ]);

        return null;
    }

    /**
     * Set cache for query response
     *
     * @param string $query La query
     * @param string $botId ID du bot
     * @param array $response La réponse à cacher
     * @param int $ttl TTL en secondes (default: 30min)
     */
    public function set(string $query, string $botId, array $response, int $ttl = self::L2_TTL): void
    {
        $cacheKey = $this->getCacheKey($query, $botId);

        // Store in L1 (in-memory)
        $this->setL1($cacheKey, $response);

        // Store in L2 (Redis)
        Redis::setex($cacheKey, $ttl, json_encode($response));

        // Store query embedding for similarity search
        $this->storeQueryEmbedding($query, $botId, $cacheKey);

        Log::debug('Cache SET', [
            'query' => substr($query, 0, 50),
            'ttl' => $ttl,
        ]);
    }

    /**
     * Find similar cached query via embedding similarity
     *
     * @param string $query La query
     * @param string $botId ID du bot
     * @param float $threshold Seuil de similarité (0-1)
     * @return array|null ['query' => ..., 'response' => ..., 'similarity' => ...]
     */
    public function getSimilar(string $query, string $botId, float $threshold = self::SIMILARITY_THRESHOLD): ?array
    {
        try {
            // Generate query embedding
            $queryEmbedding = $this->embeddingService->generateEmbedding($query);

            // Get all cached query embeddings for this bot
            $embeddingsKey = $this->getEmbeddingsKey($botId);
            $cachedEmbeddings = Redis::hgetall($embeddingsKey);

            if (empty($cachedEmbeddings)) {
                return null;
            }

            $bestMatch = null;
            $bestSimilarity = 0;

            foreach ($cachedEmbeddings as $cacheKey => $embeddingJson) {
                $cached = json_decode($embeddingJson, true);
                $cachedEmbedding = $cached['embedding'];

                // Calculate cosine similarity
                $similarity = $this->embeddingService->cosineSimilarity($queryEmbedding, $cachedEmbedding);

                if ($similarity > $bestSimilarity && $similarity >= $threshold) {
                    $bestSimilarity = $similarity;
                    $bestMatch = [
                        'cache_key' => $cacheKey,
                        'query' => $cached['query'],
                        'similarity' => $similarity,
                    ];
                }
            }

            if ($bestMatch) {
                // Retrieve cached response
                $responseJson = Redis::get($bestMatch['cache_key']);
                if ($responseJson) {
                    $response = json_decode($responseJson, true);

                    return [
                        'query' => $bestMatch['query'],
                        'response' => $response,
                        'similarity' => $bestMatch['similarity'],
                    ];
                }
            }

            return null;

        } catch (\Exception $e) {
            Log::warning('Similarity search failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);
            return null;
        }
    }

    /**
     * Clear cache for bot
     *
     * @param string $botId ID du bot
     */
    public function clear(string $botId): void
    {
        // Clear L1 (filter by bot)
        foreach (self::$l1Cache as $key => $value) {
            if (str_contains($key, "bot:{$botId}:")) {
                unset(self::$l1Cache[$key]);
            }
        }

        // Clear L2 (Redis pattern match)
        $pattern = $this->getCacheKey('*', $botId);
        $keys = Redis::keys($pattern);
        if (!empty($keys)) {
            Redis::del($keys);
        }

        // Clear embeddings
        Redis::del($this->getEmbeddingsKey($botId));

        Log::info('Cache cleared', ['bot_id' => $botId]);
    }

    /**
     * Get cache statistics
     *
     * @return array Stats (hit rate, hits, misses)
     */
    public function getStats(): array
    {
        $total = self::$l1Hits + self::$l2Hits + self::$misses;
        $hitRate = $total > 0 ? ((self::$l1Hits + self::$l2Hits) / $total) * 100 : 0;

        return [
            'l1_hits' => self::$l1Hits,
            'l2_hits' => self::$l2Hits,
            'total_hits' => self::$l1Hits + self::$l2Hits,
            'misses' => self::$misses,
            'total_requests' => $total,
            'hit_rate' => round($hitRate, 2),
            'l1_size' => count(self::$l1Cache),
        ];
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        self::$l1Hits = 0;
        self::$l2Hits = 0;
        self::$misses = 0;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PRIVATE HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Generate cache key
     */
    private function getCacheKey(string $query, string $botId): string
    {
        $hash = hash('sha256', $query);
        return "bot:{$botId}:query:{$hash}";
    }

    /**
     * Get embeddings storage key for bot
     */
    private function getEmbeddingsKey(string $botId): string
    {
        return "bot:{$botId}:query_embeddings";
    }

    /**
     * Set L1 cache with LRU eviction
     */
    private function setL1(string $key, array $value): void
    {
        // LRU: If full, remove oldest
        if (count(self::$l1Cache) >= self::L1_MAX_SIZE) {
            // Remove first (oldest) item
            array_shift(self::$l1Cache);
        }

        self::$l1Cache[$key] = $value;
    }

    /**
     * Store query embedding for similarity matching
     */
    private function storeQueryEmbedding(string $query, string $botId, string $cacheKey): void
    {
        try {
            $embedding = $this->embeddingService->generateEmbedding($query);

            $data = [
                'query' => $query,
                'embedding' => $embedding,
                'created_at' => now()->toIso8601String(),
            ];

            $embeddingsKey = $this->getEmbeddingsKey($botId);
            Redis::hset($embeddingsKey, $cacheKey, json_encode($data));

            // Set expiration on embeddings hash
            Redis::expire($embeddingsKey, self::L2_TTL);

        } catch (\Exception $e) {
            // Non-critical, just log
            Log::warning('Failed to store query embedding', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
