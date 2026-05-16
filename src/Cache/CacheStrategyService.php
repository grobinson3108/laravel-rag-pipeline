<?php

namespace Grobinson3108\LaravelRagPipeline\Cache;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * CacheStrategyService
 *
 * Gestion intelligente des stratégies de cache:
 * - Déterminer quoi cacher
 * - Calculer TTL adaptatif
 * - Éviction LRU/LFU
 * - Monitoring hit rate
 *
 * Objectif: Maximiser hit rate (>80%), minimiser mémoire usage
 */
class CacheStrategyService
{
    private const MIN_TTL = 300; // 5 minutes
    private const MAX_TTL = 86400; // 24 heures
    private const DEFAULT_TTL = 1800; // 30 minutes

    // Critères pour NE PAS cacher
    private const NO_CACHE_PATTERNS = [
        '/^(bonjour|salut|hello|hi)\b/i', // Greetings (pas de valeur cache)
        '/^(merci|thanks|thank you)\b/i', // Merci (pas de valeur cache)
        '/\b(aujourd\'hui|maintenant|en ce moment)\b/i', // Time-sensitive
        '/\b(combien de|nombre de)\b.*\b(aujourd\'hui|ce mois)\b/i', // Dynamic counts
    ];

    /**
     * Determine if query should be cached
     *
     * Critères:
     * - Query length > 10 chars
     * - Pas de patterns "no cache"
     * - Pas trop générique (ex: "oui", "non")
     * - Pas time-sensitive
     *
     * @param string $query La query
     * @param array $metadata Metadata additionnelle
     * @return bool True si devrait être caché
     */
    public function shouldCache(string $query, array $metadata = []): bool
    {
        // Too short
        if (strlen($query) < 10) {
            Log::debug('Query too short to cache', ['query' => $query]);
            return false;
        }

        // Check NO_CACHE patterns
        foreach (self::NO_CACHE_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                Log::debug('Query matches NO_CACHE pattern', [
                    'query' => $query,
                    'pattern' => $pattern,
                ]);
                return false;
            }
        }

        // Check metadata flags
        if (isset($metadata['no_cache']) && $metadata['no_cache']) {
            Log::debug('Metadata indicates NO_CACHE', ['query' => $query]);
            return false;
        }

        // Check if time-sensitive
        if ($this->isTimeSensitive($query)) {
            Log::debug('Query is time-sensitive', ['query' => $query]);
            return false;
        }

        // Check if user-specific data
        if ($this->isUserSpecific($query, $metadata)) {
            // User-specific can be cached but with shorter TTL
            return true;
        }

        return true;
    }

    /**
     * Calculate adaptive TTL based on query characteristics
     *
     * Facteurs:
     * - Type de query (FAQ = long, user-specific = court)
     * - Fréquence d'utilisation (fréquent = long)
     * - Time-sensitivity (date/time references = court)
     * - Confidence score (bas = court)
     *
     * @param string $query La query
     * @param array $usage Usage stats (frequency, last_used, etc.)
     * @param array $metadata Metadata response
     * @return int TTL en secondes
     */
    public function calculateTTL(string $query, array $usage = [], array $metadata = []): int
    {
        $ttl = self::DEFAULT_TTL; // Base: 30 min

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FACTOR 1: Query Type
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        // FAQ-like (questions courantes)
        if ($this->isFAQ($query)) {
            $ttl *= 2; // 1h
        }

        // User-specific
        if ($this->isUserSpecific($query, $metadata)) {
            $ttl *= 0.5; // 15 min
        }

        // Time-sensitive
        if ($this->isTimeSensitive($query)) {
            $ttl *= 0.3; // 9 min
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FACTOR 2: Frequency (usage)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        if (isset($usage['frequency'])) {
            $frequency = $usage['frequency'];

            if ($frequency > 100) {
                // Très fréquent (>100 fois) → cache plus longtemps
                $ttl *= 3; // 90 min (ou plus)
            } elseif ($frequency > 50) {
                // Fréquent (50-100 fois) → cache longtemps
                $ttl *= 2;
            } elseif ($frequency > 10) {
                // Modéré (10-50 fois) → TTL normal
                $ttl *= 1.5;
            } elseif ($frequency < 3) {
                // Rare (<3 fois) → cache court
                $ttl *= 0.5;
            }
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FACTOR 3: Confidence Score
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        if (isset($metadata['confidence'])) {
            $confidence = $metadata['confidence'];

            if ($confidence < 0.5) {
                // Low confidence → cache très court (éviter propager mauvaises réponses)
                $ttl *= 0.2;
            } elseif ($confidence < 0.7) {
                // Medium confidence → cache court
                $ttl *= 0.5;
            }
            // High confidence → TTL normal/long
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // FACTOR 4: Response Source
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        if (isset($metadata['source'])) {
            $source = $metadata['source'];

            if ($source === 'faq') {
                // FAQ source → cache très long
                $ttl *= 4; // 2h
            } elseif ($source === 'master_training') {
                // Master training → cache long
                $ttl *= 3;
            } elseif ($source === 'field_learning') {
                // Field learning → cache modéré (peut changer)
                $ttl *= 1;
            } elseif ($source === 'llm') {
                // LLM généré → cache court (générique)
                $ttl *= 0.7;
            }
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // CLAMP TTL
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

        $ttl = max(self::MIN_TTL, min($ttl, self::MAX_TTL));

        Log::debug('Calculated TTL', [
            'query' => substr($query, 0, 50),
            'ttl' => $ttl,
            'frequency' => $usage['frequency'] ?? 0,
            'confidence' => $metadata['confidence'] ?? 'N/A',
        ]);

        return (int) $ttl;
    }

    /**
     * Get overall cache hit rate
     *
     * @param string|null $botId ID bot (optionnel)
     * @return float Hit rate (0-100)
     */
    public function getHitRate(?string $botId = null): float
    {
        // TODO: Tracker hits/misses dans Redis
        // Pour l'instant, retourner dummy value

        // Clé stats: "cache:stats:{botId}:hits" et "cache:stats:{botId}:misses"
        $statsKey = $botId ? "cache:stats:{$botId}" : "cache:stats:global";

        $hits = (int) Redis::hget($statsKey, 'hits') ?: 0;
        $misses = (int) Redis::hget($statsKey, 'misses') ?: 0;
        $total = $hits + $misses;

        if ($total == 0) {
            return 0.0;
        }

        return ($hits / $total) * 100;
    }

    /**
     * Evict cache if needed (memory pressure)
     *
     * Stratégie:
     * - LRU (Least Recently Used)
     * - LFU (Least Frequently Used)
     * - Combinaison des deux
     *
     * @param string $botId ID bot
     * @param int $targetSize Target size (nombre d'items)
     */
    public function evictIfNeeded(string $botId, int $targetSize = 1000): void
    {
        // Get current cache size
        $pattern = "bot:{$botId}:query:*";
        $keys = Redis::keys($pattern);
        $currentSize = count($keys);

        if ($currentSize <= $targetSize) {
            Log::debug('No eviction needed', [
                'bot_id' => $botId,
                'current_size' => $currentSize,
                'target_size' => $targetSize,
            ]);
            return;
        }

        // Need to evict
        $toEvict = $currentSize - $targetSize;

        Log::info('Cache eviction starting', [
            'bot_id' => $botId,
            'current_size' => $currentSize,
            'target_size' => $targetSize,
            'to_evict' => $toEvict,
        ]);

        // Get TTL for each key and evict those expiring soonest
        $keyTTLs = [];
        foreach ($keys as $key) {
            $ttl = Redis::ttl($key);
            if ($ttl > 0) {
                $keyTTLs[$key] = $ttl;
            }
        }

        // Sort by TTL (ascending)
        asort($keyTTLs);

        // Evict first $toEvict keys
        $evicted = 0;
        foreach ($keyTTLs as $key => $ttl) {
            if ($evicted >= $toEvict) {
                break;
            }

            Redis::del($key);
            $evicted++;
        }

        Log::info('Cache eviction completed', [
            'bot_id' => $botId,
            'evicted' => $evicted,
            'remaining' => $currentSize - $evicted,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PRIVATE HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Check if query is FAQ-like
     */
    private function isFAQ(string $query): bool
    {
        $faqPatterns = [
            '/^(comment|pourquoi|quand|où|qui|quel|quelle)\b/i',
            '/\?$/', // Ends with question mark
            '/^c\'est quoi\b/i',
            '/^qu\'est-ce que\b/i',
        ];

        foreach ($faqPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if query is time-sensitive
     */
    private function isTimeSensitive(string $query): bool
    {
        $timeSensitivePatterns = [
            '/\b(aujourd\'hui|maintenant|actuellement|en ce moment)\b/i',
            '/\b(cette (semaine|année|mois))\b/i',
            '/\b(ce (mois|trimestre))\b/i',
            '/\b(hier|demain|prochainement)\b/i',
            '/\b(combien de|nombre de)\b.*\b(clients|users|ventes)\b/i', // Dynamic counts
        ];

        foreach ($timeSensitivePatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if query is user-specific
     */
    private function isUserSpecific(string $query, array $metadata): bool
    {
        // Check metadata
        if (isset($metadata['user_specific']) && $metadata['user_specific']) {
            return true;
        }

        // Check query patterns
        $userSpecificPatterns = [
            '/\b(mon|ma|mes)\b/i', // "mon compte", "mes commandes"
            '/\b(je|j\'|moi)\b/i', // "je veux", "moi"
        ];

        foreach ($userSpecificPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }
}
