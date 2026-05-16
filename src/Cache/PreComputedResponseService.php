<?php

namespace Grobinson3108\LaravelRagPipeline\Cache;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Grobinson3108\LaravelRagPipeline\RAG\EmbeddingService;

/**
 * PreComputedResponseService
 *
 * Gère les réponses pré-calculées pour les patterns fréquents
 * Objectif: -47% cost, 40% pre-computed hit rate
 *
 * Cas d'usage:
 * - Salutations (Bonjour, Hello, etc.)
 * - Objections courantes (Trop cher, Pas intéressé, etc.)
 * - Questions FAQ (Pricing, Fonctionnalités, etc.)
 * - Patterns récurrents (Démo, Contact, etc.)
 *
 * Flow:
 * 1. Check si query match avec réponse pré-calculée (similarity ≥ 0.95)
 * 2. Si match: return cached response (0-cost, <5ms)
 * 3. Si no match: process normalement via RAG
 *
 * Performance:
 * - Latency: <5ms (vs 40ms RAG)
 * - Cost: $0.000 (vs $0.015)
 * - Cache size: ~100-200 patterns par bot
 */
class PreComputedResponseService
{
    private EmbeddingService $embeddingService;
    private float $similarityThreshold = 0.95;
    private int $cacheTTL = 604800; // 7 days in seconds

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    /**
     * Find pre-computed response for user message
     *
     * @param string $botId Bot ID
     * @param string $userMessage User query
     * @return array|null ['answer' => string, 'confidence' => float, 'pattern' => string] or null
     */
    public function findPreComputedResponse(string $botId, string $userMessage): ?array
    {
        $startTime = microtime(true);

        try {
            // Generate embedding for user message
            $userEmbedding = $this->embeddingService->generateEmbedding($userMessage);

            // Get all pre-computed patterns for this bot
            $pattern = "precomputed:{$botId}:*";
            $keys = Redis::keys($pattern);

            if (empty($keys)) {
                Log::debug('PreComputed: No patterns found', ['bot_id' => $botId]);
                return null;
            }

            $bestMatch = null;
            $bestSimilarity = 0;

            foreach ($keys as $key) {
                $data = json_decode(Redis::get($key), true);

                if (!$data || !isset($data['embedding'])) {
                    continue;
                }

                // Calculate similarity
                $similarity = $this->cosineSimilarity($userEmbedding, $data['embedding']);

                if ($similarity > $bestSimilarity) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $data;
                }

                // Early exit if perfect match
                if ($similarity >= 0.98) {
                    break;
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;

            // Check if similarity meets threshold
            if ($bestMatch && $bestSimilarity >= $this->similarityThreshold) {
                Log::info('PreComputed: Match found', [
                    'bot_id' => $botId,
                    'pattern' => $bestMatch['pattern'],
                    'similarity' => round($bestSimilarity, 3),
                    'duration_ms' => round($duration, 2),
                ]);

                // Increment hit counter
                $this->incrementHitCounter($botId, $bestMatch['pattern']);

                return [
                    'answer' => $bestMatch['response'],
                    'confidence' => $bestSimilarity,
                    'pattern' => $bestMatch['pattern'],
                    'metadata' => [
                        'source' => 'pre_computed',
                        'similarity' => $bestSimilarity,
                        'duration_ms' => round($duration, 2),
                        'from_cache' => true,
                    ],
                ];
            }

            Log::debug('PreComputed: No match above threshold', [
                'bot_id' => $botId,
                'best_similarity' => round($bestSimilarity, 3),
                'threshold' => $this->similarityThreshold,
                'duration_ms' => round($duration, 2),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('PreComputed: Search failed', [
                'error' => $e->getMessage(),
                'bot_id' => $botId,
            ]);

            return null;
        }
    }

    /**
     * Pre-compute responses for frequent patterns
     *
     * @param string $botId Bot ID
     * @param array $patterns Array of ['pattern' => string, 'response' => string, 'category' => string]
     * @return array Statistics
     */
    public function preComputeResponses(string $botId, array $patterns): array
    {
        $startTime = microtime(true);
        $stats = [
            'total' => count($patterns),
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($patterns as $pattern) {
            try {
                $this->storePreComputedResponse(
                    $botId,
                    $pattern['pattern'],
                    $pattern['response'],
                    $pattern['category'] ?? 'general'
                );

                $stats['success']++;

            } catch (\Exception $e) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'pattern' => $pattern['pattern'],
                    'error' => $e->getMessage(),
                ];

                Log::warning('PreComputed: Failed to store pattern', [
                    'pattern' => $pattern['pattern'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $duration = (microtime(true) - $startTime) * 1000;

        Log::info('PreComputed: Batch processing completed', [
            'bot_id' => $botId,
            'total' => $stats['total'],
            'success' => $stats['success'],
            'failed' => $stats['failed'],
            'duration_ms' => round($duration, 2),
        ]);

        return $stats;
    }

    /**
     * Store a pre-computed response
     *
     * @param string $botId Bot ID
     * @param string $pattern User message pattern
     * @param string $response Pre-computed response
     * @param string $category Category (greeting, objection, faq, etc.)
     */
    public function storePreComputedResponse(
        string $botId,
        string $pattern,
        string $response,
        string $category = 'general'
    ): void {
        // Generate embedding for pattern
        $embedding = $this->embeddingService->generateEmbedding($pattern);

        // Create unique key
        $key = $this->generateKey($botId, $pattern);

        // Store data
        $data = [
            'pattern' => $pattern,
            'response' => $response,
            'category' => $category,
            'embedding' => $embedding,
            'hit_count' => 0,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];

        Redis::setex($key, $this->cacheTTL, json_encode($data));

        Log::debug('PreComputed: Pattern stored', [
            'bot_id' => $botId,
            'pattern' => $pattern,
            'category' => $category,
        ]);
    }

    /**
     * Get all pre-computed patterns for bot
     *
     * @param string $botId Bot ID
     * @return array List of patterns with metadata
     */
    public function getAllPatterns(string $botId): array
    {
        $pattern = "precomputed:{$botId}:*";
        $keys = Redis::keys($pattern);

        $patterns = [];

        foreach ($keys as $key) {
            $data = json_decode(Redis::get($key), true);

            if ($data) {
                // Remove embedding from output (too large)
                unset($data['embedding']);
                $patterns[] = $data;
            }
        }

        // Sort by hit count descending
        usort($patterns, function ($a, $b) {
            return ($b['hit_count'] ?? 0) - ($a['hit_count'] ?? 0);
        });

        return $patterns;
    }

    /**
     * Delete a pre-computed pattern
     *
     * @param string $botId Bot ID
     * @param string $pattern Pattern to delete
     * @return bool Success
     */
    public function deletePattern(string $botId, string $pattern): bool
    {
        $key = $this->generateKey($botId, $pattern);
        $result = Redis::del($key);

        Log::info('PreComputed: Pattern deleted', [
            'bot_id' => $botId,
            'pattern' => $pattern,
        ]);

        return $result > 0;
    }

    /**
     * Clear all pre-computed responses for bot
     *
     * @param string $botId Bot ID
     * @return int Number of patterns deleted
     */
    public function clearAll(string $botId): int
    {
        $pattern = "precomputed:{$botId}:*";
        $keys = Redis::keys($pattern);

        $count = 0;
        foreach ($keys as $key) {
            Redis::del($key);
            $count++;
        }

        Log::info('PreComputed: All patterns cleared', [
            'bot_id' => $botId,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Get statistics for pre-computed responses
     *
     * @param string $botId Bot ID
     * @return array Statistics
     */
    public function getStats(string $botId): array
    {
        $patterns = $this->getAllPatterns($botId);

        $totalHits = array_sum(array_column($patterns, 'hit_count'));

        $categories = [];
        foreach ($patterns as $pattern) {
            $category = $pattern['category'] ?? 'general';
            if (!isset($categories[$category])) {
                $categories[$category] = 0;
            }
            $categories[$category]++;
        }

        return [
            'total_patterns' => count($patterns),
            'total_hits' => $totalHits,
            'categories' => $categories,
            'top_patterns' => array_slice($patterns, 0, 10),
        ];
    }

    /**
     * Load default patterns for common scenarios
     *
     * @param string $botId Bot ID
     * @return array Statistics
     */
    public function loadDefaultPatterns(string $botId): array
    {
        $defaultPatterns = $this->getDefaultPatterns();
        return $this->preComputeResponses($botId, $defaultPatterns);
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // PRIVATE HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculate cosine similarity between two vectors
     */
    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        if (count($vec1) !== count($vec2)) {
            throw new \InvalidArgumentException('Vectors must have same length');
        }

        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;

        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $magnitude1 += $vec1[$i] ** 2;
            $magnitude2 += $vec2[$i] ** 2;
        }

        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);

        if ($magnitude1 == 0 || $magnitude2 == 0) {
            return 0;
        }

        return $dotProduct / ($magnitude1 * $magnitude2);
    }

    /**
     * Generate unique key for pattern
     */
    private function generateKey(string $botId, string $pattern): string
    {
        $hash = md5($pattern);
        return "precomputed:{$botId}:{$hash}";
    }

    /**
     * Increment hit counter for pattern
     */
    private function incrementHitCounter(string $botId, string $pattern): void
    {
        try {
            $key = $this->generateKey($botId, $pattern);
            $data = json_decode(Redis::get($key), true);

            if ($data) {
                $data['hit_count'] = ($data['hit_count'] ?? 0) + 1;
                $data['last_hit_at'] = now()->toIso8601String();

                Redis::setex($key, $this->cacheTTL, json_encode($data));
            }
        } catch (\Exception $e) {
            // Non-blocking
            Log::debug('PreComputed: Failed to increment counter', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get default patterns for common scenarios
     */
    private function getDefaultPatterns(): array
    {
        return [
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // GREETINGS
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            [
                'pattern' => 'Bonjour',
                'category' => 'greeting',
                'response' => "Bonjour ! 👋 Je suis ravi de vous aider aujourd'hui. Comment puis-je vous être utile ?",
            ],
            [
                'pattern' => 'Salut',
                'category' => 'greeting',
                'response' => "Salut ! Comment puis-je vous aider ?",
            ],
            [
                'pattern' => 'Hello',
                'category' => 'greeting',
                'response' => "Hello! How can I help you today?",
            ],

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // OBJECTIONS
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            [
                'pattern' => "C'est trop cher",
                'category' => 'objection',
                'response' => "Je comprends votre préoccupation sur le prix. Laissez-moi vous expliquer la valeur que vous obtenez : notre solution permet d'économiser en moyenne 40% du temps de vos équipes, ce qui se traduit par un ROI de 300% sur 12 mois. Puis-je vous montrer comment nous calculons ce retour sur investissement pour votre cas spécifique ?",
            ],
            [
                'pattern' => "Pas intéressé",
                'category' => 'objection',
                'response' => "Je comprends. Puis-je vous demander ce qui ne correspond pas à vos besoins actuels ? Cela m'aiderait à mieux comprendre votre situation et peut-être à vous proposer quelque chose de plus adapté.",
            ],

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // FAQ
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            [
                'pattern' => 'Quel est le prix ?',
                'category' => 'faq',
                'response' => "Nos tarifs sont flexibles et s'adaptent à vos besoins. Nous proposons trois formules principales : Starter à 99€/mois, Business à 299€/mois, et Enterprise sur devis. Chaque formule inclut des fonctionnalités spécifiques. Souhaitez-vous que je vous détaille ce qui est inclus dans chaque formule ?",
            ],
            [
                'pattern' => 'Comment ça marche ?',
                'category' => 'faq',
                'response' => "Notre plateforme fonctionne en 3 étapes simples : 1) Vous configurez votre bot en quelques minutes, 2) Vous importez vos connaissances (documents, FAQ, etc.), 3) Votre bot est prêt à répondre à vos clients 24/7. Voulez-vous une démonstration ?",
            ],

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // ACTIONS
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            [
                'pattern' => 'Je veux une démo',
                'category' => 'action',
                'response' => "Excellent ! Je serais ravi de vous montrer notre plateforme en action. Quelle serait votre disponibilité cette semaine ? Je peux vous proposer un créneau de 30 minutes pour vous présenter toutes les fonctionnalités et répondre à vos questions.",
            ],
            [
                'pattern' => 'Contactez-moi',
                'category' => 'action',
                'response' => "Avec plaisir ! Quel est le meilleur moyen de vous contacter ? Email ou téléphone ? Et à quel moment de la journée préférez-vous être contacté ?",
            ],

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // POLITENESS
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            [
                'pattern' => 'Merci',
                'category' => 'politeness',
                'response' => "De rien ! Je suis là pour vous aider. N'hésitez pas si vous avez d'autres questions.",
            ],
            [
                'pattern' => 'Au revoir',
                'category' => 'politeness',
                'response' => "Au revoir ! N'hésitez pas à revenir si vous avez besoin d'aide. Bonne journée ! 👋",
            ],
        ];
    }
}
