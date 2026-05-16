<?php

namespace Grobinson3108\LaravelRagPipeline\Router;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * QueryClassifierService
 *
 * Classifie les requêtes utilisateur en 3 types:
 * - STRUCTURED: Requêtes factuelles (Knowledge Graph via Neo4j)
 * - SEMANTIC: Requêtes conceptuelles (Vector RAG via ChromaDB)
 * - COMPLEX: Requêtes nécessitant raisonnement (LLM direct)
 *
 * Objectif: >85% précision, <50ms latence
 *
 * Exemples:
 * STRUCTURED: "Quel est le prix du produit X ?", "Horaires d'ouverture ?"
 * SEMANTIC: "Comment améliorer mon processus de vente ?", "Expliquez votre approche"
 * COMPLEX: "Comparez nos solutions et recommandez la meilleure pour mon cas"
 */
class QueryClassifierService
{
    private const CLASSIFICATION_MODEL = 'gpt-4o-mini';
    private const CACHE_TTL = 86400; // 24h
    private const TIMEOUT = 3; // 3s max

    // Classification types
    public const TYPE_STRUCTURED = 'STRUCTURED';
    public const TYPE_SEMANTIC = 'SEMANTIC';
    public const TYPE_COMPLEX = 'COMPLEX';

    private string $apiKey;
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';
    private bool $useLLM = true;

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
        $this->useLLM = config('services.openai.use_llm_classification', true);
    }

    /**
     * Classify query into type
     *
     * @param string $query User query
     * @param bool $useCache Use cached classification if available
     * @return string TYPE_STRUCTURED|TYPE_SEMANTIC|TYPE_COMPLEX
     */
    public function classify(string $query, bool $useCache = true): string
    {
        $startTime = microtime(true);

        // Check cache
        if ($useCache) {
            $cached = $this->getCachedClassification($query);
            if ($cached !== null) {
                $duration = (microtime(true) - $startTime) * 1000;

                Log::debug('Classification CACHE HIT', [
                    'query' => substr($query, 0, 50),
                    'type' => $cached,
                    'duration_ms' => round($duration, 2),
                ]);

                return $cached;
            }
        }

        // Classify
        if ($this->useLLM) {
            $type = $this->classifyWithLLM($query);
        } else {
            $type = $this->classifyWithHeuristic($query);
        }

        // Cache result
        $this->cacheClassification($query, $type);

        $duration = (microtime(true) - $startTime) * 1000;

        Log::info('Query classified', [
            'query' => substr($query, 0, 50),
            'type' => $type,
            'method' => $this->useLLM ? 'llm' : 'heuristic',
            'duration_ms' => round($duration, 2),
        ]);

        return $type;
    }

    /**
     * Get classification confidence score
     *
     * @param string $query User query
     * @return float Confidence (0-1)
     */
    public function getConfidence(string $query): float
    {
        if (!$this->useLLM) {
            return $this->getHeuristicConfidence($query);
        }

        try {
            $classification = $this->classifyWithDetails($query);
            return $classification['confidence'];

        } catch (\Exception $e) {
            Log::warning('Failed to get confidence', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return 0.5; // Default medium confidence
        }
    }

    /**
     * Explain classification with reasoning
     *
     * @param string $query User query
     * @return array ['type' => string, 'confidence' => float, 'reasoning' => string]
     */
    public function explainClassification(string $query): array
    {
        if (!$this->useLLM) {
            return $this->explainWithHeuristic($query);
        }

        try {
            return $this->classifyWithDetails($query);

        } catch (\Exception $e) {
            Log::error('Failed to explain classification', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return [
                'type' => self::TYPE_SEMANTIC,
                'confidence' => 0.5,
                'reasoning' => 'Fallback to semantic search due to classification error',
            ];
        }
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LLM CLASSIFICATION
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Classify using GPT-4-mini
     */
    private function classifyWithLLM(string $query): string
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])
            ->timeout(self::TIMEOUT)
            ->post($this->apiUrl, [
                'model' => self::CLASSIFICATION_MODEL,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->getSystemPrompt(),
                    ],
                    [
                        'role' => 'user',
                        'content' => "Classify this query:\n\n" . $query,
                    ],
                ],
                'temperature' => 0.0,
                'max_tokens' => 50,
            ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI API error: ' . $response->status());
            }

            $content = $response->json()['choices'][0]['message']['content'] ?? '';

            // Extract type from response
            if (stripos($content, 'STRUCTURED') !== false) {
                return self::TYPE_STRUCTURED;
            } elseif (stripos($content, 'COMPLEX') !== false) {
                return self::TYPE_COMPLEX;
            } else {
                return self::TYPE_SEMANTIC;
            }

        } catch (\Exception $e) {
            Log::warning('LLM classification failed, using heuristic', [
                'error' => $e->getMessage(),
            ]);

            return $this->classifyWithHeuristic($query);
        }
    }

    /**
     * Classify with detailed reasoning
     */
    private function classifyWithDetails(string $query): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout(self::TIMEOUT)
        ->post($this->apiUrl, [
            'model' => self::CLASSIFICATION_MODEL,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->getSystemPromptWithExplanation(),
                ],
                [
                    'role' => 'user',
                    'content' => "Classify and explain this query:\n\n" . $query,
                ],
            ],
            'temperature' => 0.0,
            'max_tokens' => 150,
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI API error: ' . $response->status());
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? '';

        // Parse JSON response
        // Expected format: {"type": "STRUCTURED", "confidence": 0.95, "reasoning": "..."}
        try {
            $data = json_decode($content, true);

            if (!isset($data['type']) || !isset($data['confidence']) || !isset($data['reasoning'])) {
                throw new \Exception('Invalid response format');
            }

            return [
                'type' => $data['type'],
                'confidence' => (float) $data['confidence'],
                'reasoning' => $data['reasoning'],
            ];

        } catch (\Exception $e) {
            // Fallback: Extract type manually
            if (stripos($content, 'STRUCTURED') !== false) {
                $type = self::TYPE_STRUCTURED;
            } elseif (stripos($content, 'COMPLEX') !== false) {
                $type = self::TYPE_COMPLEX;
            } else {
                $type = self::TYPE_SEMANTIC;
            }

            return [
                'type' => $type,
                'confidence' => 0.7,
                'reasoning' => $content,
            ];
        }
    }

    /**
     * System prompt for classification
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a query classifier. Classify user queries into exactly ONE of these types:

**STRUCTURED**: Factual queries with clear answers (Knowledge Graph)
Examples:
- "Quel est le prix du produit X ?"
- "Horaires d'ouverture ?"
- "Combien de clients avons-nous ?"
- "Qui est le responsable du projet Y ?"
- "Quelle est l'adresse de votre siège ?"

**SEMANTIC**: Conceptual queries requiring context understanding (Vector RAG)
Examples:
- "Comment améliorer mon processus de vente ?"
- "Expliquez votre approche méthodologique"
- "Pourquoi choisir votre solution ?"
- "Comment fonctionne votre service ?"
- "Quels sont les avantages de..."

**COMPLEX**: Queries requiring reasoning, comparison, or multi-step analysis (LLM)
Examples:
- "Comparez vos offres et recommandez la meilleure pour une PME de 50 personnes"
- "Analysez mon besoin et proposez une solution personnalisée"
- "Si je veux X et Y, quelle stratégie dois-je adopter ?"
- "Créez-moi un plan d'action basé sur..."

Respond with ONLY the type: STRUCTURED, SEMANTIC, or COMPLEX.
PROMPT;
    }

    /**
     * System prompt with explanation
     */
    private function getSystemPromptWithExplanation(): string
    {
        return <<<PROMPT
You are a query classifier. Classify user queries into exactly ONE of these types:

**STRUCTURED**: Factual queries with clear answers (Knowledge Graph)
**SEMANTIC**: Conceptual queries requiring context understanding (Vector RAG)
**COMPLEX**: Queries requiring reasoning, comparison, or multi-step analysis (LLM)

Respond in JSON format:
{
    "type": "STRUCTURED|SEMANTIC|COMPLEX",
    "confidence": 0.0-1.0,
    "reasoning": "Brief explanation why this classification"
}

Examples:
- "Quel est le prix ?" → STRUCTURED (factual, simple lookup)
- "Comment améliorer mes ventes ?" → SEMANTIC (conceptual, needs context)
- "Comparez et recommandez" → COMPLEX (multi-step reasoning)
PROMPT;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HEURISTIC CLASSIFICATION (Fallback)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Classify using heuristic rules (fast fallback)
     */
    private function classifyWithHeuristic(string $query): string
    {
        $queryLower = mb_strtolower($query);

        // COMPLEX indicators
        $complexPatterns = [
            '/\b(comparer|comparez|comparaison)\b/i',
            '/\b(recommander|recommandez|recommandation)\b/i',
            '/\b(analyser|analysez|analyse)\b/i',
            '/\b(créer|créez|générer|générez)\b/i',
            '/\b(si .* alors|si .* que)\b/i',
            '/\b(plan|stratégie|approche personnalisée)\b/i',
        ];

        foreach ($complexPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return self::TYPE_COMPLEX;
            }
        }

        // STRUCTURED indicators
        $structuredPatterns = [
            '/^(quel|quelle|quels|quelles)\s+(est|sont)\s+(le|la|les)\b/i',
            '/^(combien|nombre de)\b/i',
            '/^(qui est|qui sont)\b/i',
            '/\b(prix|tarif|coût|horaires|adresse|téléphone)\b/i',
            '/\?$/', // Simple question mark
        ];

        $structuredCount = 0;
        foreach ($structuredPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $structuredCount++;
            }
        }

        if ($structuredCount >= 2) {
            return self::TYPE_STRUCTURED;
        }

        // SEMANTIC indicators
        $semanticPatterns = [
            '/\b(comment|pourquoi|expliquer|expliquez|explication)\b/i',
            '/\b(améliorer|optimiser|développer)\b/i',
            '/\b(avantages|bénéfices|fonctionnement)\b/i',
        ];

        foreach ($semanticPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return self::TYPE_SEMANTIC;
            }
        }

        // Default: SEMANTIC (most common)
        return self::TYPE_SEMANTIC;
    }

    /**
     * Get confidence for heuristic classification
     */
    private function getHeuristicConfidence(string $query): float
    {
        $type = $this->classifyWithHeuristic($query);

        // Count matching patterns
        $matches = 0;

        if ($type === self::TYPE_COMPLEX) {
            $patterns = ['/\b(comparer|recommander|analyser|créer)\b/i'];
        } elseif ($type === self::TYPE_STRUCTURED) {
            $patterns = ['/^(quel|combien|qui)\b/i', '/\b(prix|horaires)\b/i'];
        } else {
            $patterns = ['/\b(comment|pourquoi|expliquer)\b/i'];
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query)) {
                $matches++;
            }
        }

        // More matches = higher confidence
        return min(0.5 + ($matches * 0.2), 0.95);
    }

    /**
     * Explain heuristic classification
     */
    private function explainWithHeuristic(string $query): array
    {
        $type = $this->classifyWithHeuristic($query);
        $confidence = $this->getHeuristicConfidence($query);

        $reasoning = match ($type) {
            self::TYPE_STRUCTURED => 'Query contains factual question patterns (quel, combien, prix, etc.)',
            self::TYPE_COMPLEX => 'Query requires reasoning (comparer, analyser, recommander, etc.)',
            self::TYPE_SEMANTIC => 'Query is conceptual (comment, pourquoi, expliquer, etc.)',
            default => 'Default classification',
        };

        return [
            'type' => $type,
            'confidence' => $confidence,
            'reasoning' => $reasoning,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // CACHING
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Get cached classification
     */
    private function getCachedClassification(string $query): ?string
    {
        $cacheKey = $this->getClassificationCacheKey($query);
        return Cache::get($cacheKey);
    }

    /**
     * Cache classification
     */
    private function cacheClassification(string $query, string $type): void
    {
        $cacheKey = $this->getClassificationCacheKey($query);
        Cache::put($cacheKey, $type, self::CACHE_TTL);
    }

    /**
     * Generate cache key for classification
     */
    private function getClassificationCacheKey(string $query): string
    {
        $hash = hash('sha256', $query);
        return "classification:{$hash}";
    }
}
