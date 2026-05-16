<?php

namespace Grobinson3108\LaravelRagPipeline\RAG;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Grobinson3108\LaravelRagPipeline\Cache\QueryCacheService;
use Grobinson3108\LaravelRagPipeline\Cache\CacheStrategyService;
use Grobinson3108\LaravelRagPipeline\Cache\PreComputedResponseService;
use Grobinson3108\LaravelRagPipeline\Router\QueryClassifierService;
use Grobinson3108\LaravelRagPipeline\Router\QueryRouterService;
use Grobinson3108\LaravelRagPipeline\Memory\WorkingMemoryService;
use Grobinson3108\LaravelRagPipeline\RAG\CohereRerankService;

/**
 * RAGOrchestratorService
 *
 * Orchestre le pipeline complet RAG+KG+CAG:
 * 1. Check cache (QueryCacheService)
 * 2. Classify query (QueryClassifierService)
 * 3. Route to appropriate service (QueryRouterService)
 * 4. Retrieve context (Knowledge Graph + Vector RAG)
 * 5. Rerank results (RerankingService)
 * 6. Generate response (LLM with context)
 * 7. Update cache (QueryCacheService)
 *
 * Objectif: <80ms P95, >80% cache hit rate
 */
class RAGOrchestratorService
{
    private QueryCacheService $cacheService;
    private CacheStrategyService $cacheStrategy;
    private PreComputedResponseService $preComputedService;
    private QueryClassifierService $classifier;
    private QueryRouterService $router;
    private WorkingMemoryService $workingMemory;
    private RerankingService $rerankingService;
    private CohereRerankService $cohereRerank;

    private const LLM_MODEL = 'gpt-4o-mini';
    private const LLM_TIMEOUT = 10; // 10s max
    private const MAX_CONTEXT_LENGTH = 4000; // tokens
    private const MAX_RESPONSE_TOKENS = 500;

    private string $apiKey;
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        QueryCacheService $cacheService,
        CacheStrategyService $cacheStrategy,
        PreComputedResponseService $preComputedService,
        QueryClassifierService $classifier,
        QueryRouterService $router,
        WorkingMemoryService $workingMemory,
        RerankingService $rerankingService,
        CohereRerankService $cohereRerank
    ) {
        $this->cacheService = $cacheService;
        $this->cacheStrategy = $cacheStrategy;
        $this->preComputedService = $preComputedService;
        $this->classifier = $classifier;
        $this->router = $router;
        $this->workingMemory = $workingMemory;
        $this->rerankingService = $rerankingService;
        $this->cohereRerank = $cohereRerank;
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * Process query through complete RAG pipeline
     *
     * @param string $query User query
     * @param string $botId Bot ID
     * @param string|null $sessionId Session ID (optionnel)
     * @param array $options Processing options
     * @return array ['answer' => string, 'sources' => array, 'confidence' => float, 'metadata' => array]
     */
    public function process(string $query, string $botId, ?string $sessionId = null, array $options = []): array
    {
        $startTime = microtime(true);

        Log::info('RAG pipeline started', [
            'query' => substr($query, 0, 50),
            'bot_id' => $botId,
            'session_id' => $sessionId,
        ]);

        try {
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 0: Check Pre-Computed Responses (FASTEST - 0 cost)
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            if (!isset($options['skip_precomputed']) || !$options['skip_precomputed']) {
                $preComputed = $this->preComputedService->findPreComputedResponse($botId, $query);

                if ($preComputed !== null) {
                    $duration = (microtime(true) - $startTime) * 1000;

                    Log::info('RAG pipeline completed (PRE-COMPUTED HIT)', [
                        'query' => substr($query, 0, 50),
                        'pattern' => $preComputed['pattern'],
                        'duration_ms' => round($duration, 2),
                    ]);

                    return [
                        'answer' => $preComputed['answer'],
                        'sources' => [],
                        'confidence' => $preComputed['confidence'],
                        'metadata' => array_merge($preComputed['metadata'] ?? [], [
                            'total_duration_ms' => round($duration, 2),
                        ]),
                    ];
                }
            }

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 1: Check Cache (CAG)
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            if (!isset($options['skip_cache']) || !$options['skip_cache']) {
                $cached = $this->cacheService->get($query, $botId);

                if ($cached !== null) {
                    $duration = (microtime(true) - $startTime) * 1000;

                    Log::info('RAG pipeline completed (CACHE HIT)', [
                        'query' => substr($query, 0, 50),
                        'duration_ms' => round($duration, 2),
                    ]);

                    return array_merge($cached, [
                        'metadata' => array_merge($cached['metadata'] ?? [], [
                            'from_cache' => true,
                            'total_duration_ms' => round($duration, 2),
                        ]),
                    ]);
                }
            }

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 2: Retrieve Context (RAG+KG) - Multi-Stage with Cohere
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            // STAGE 1: Cast wide net - retrieve more candidates
            $finalTopK = $options['top_k'] ?? 5;
            $candidateMultiplier = $options['candidate_multiplier'] ?? 4;

            $retrievalOptions = array_merge($options, [
                'top_k' => $finalTopK * $candidateMultiplier, // e.g., 5 * 4 = 20
            ]);

            $routingResult = $this->router->route($query, $botId, $retrievalOptions);

            // If requires orchestration (COMPLEX query), use hybrid approach
            if (isset($routingResult['requires_orchestration']) && $routingResult['requires_orchestration']) {
                // Query both KG and Vector RAG for maximum context
                $routingResult = $this->router->routeMulti($query, $botId, ['graph', 'vector']);
            }

            $candidates = $routingResult['results'];
            $source = $routingResult['source'];
            $retrievalConfidence = $routingResult['confidence'];

            // STAGE 2: Re-rank with Cohere (if enabled and candidates available)
            $retrievedContext = $candidates;
            $reranked = false;

            if (!isset($options['skip_rerank']) && !empty($candidates) && count($candidates) > $finalTopK) {
                try {
                    $rerankedResults = $this->cohereRerank->rerank($query, $candidates, $finalTopK);

                    if (!empty($rerankedResults)) {
                        $retrievedContext = $rerankedResults;
                        $reranked = true;

                        Log::info('Multi-stage retrieval completed', [
                            'candidates' => count($candidates),
                            'reranked_top_k' => count($rerankedResults),
                            'top_score' => $rerankedResults[0]['relevance_score'] ?? null,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Cohere reranking failed, using original results', [
                        'error' => $e->getMessage(),
                    ]);
                    // Fallback to top-K candidates
                    $retrievedContext = array_slice($candidates, 0, $finalTopK);
                }
            } else {
                // No reranking needed or enabled - just take top-K
                $retrievedContext = array_slice($candidates, 0, $finalTopK);
            }

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 3: Get Session Context (Working Memory)
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            $sessionContext = [];
            if ($sessionId) {
                $sessionContext = $this->workingMemory->get($botId, "session:{$sessionId}") ?? [];
            }

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 4: Generate Response (LLM)
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            $response = $this->generateResponse(
                $query,
                $retrievedContext,
                $sessionContext,
                $options
            );

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 5: Calculate Confidence
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            $finalConfidence = $this->calculateFinalConfidence(
                $retrievalConfidence,
                $retrievedContext,
                $response
            );

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 6: Update Cache
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            $result = [
                'answer' => $response['content'],
                'sources' => $this->formatSources($retrievedContext),
                'confidence' => $finalConfidence,
                'metadata' => [
                    'source' => $source,
                    'retrieval_confidence' => $retrievalConfidence,
                    'retrieved_count' => count($retrievedContext),
                    'candidates_count' => count($candidates),
                    'reranked' => $reranked,
                    'classification' => $routingResult['metadata']['classification_type'] ?? 'unknown',
                    'from_cache' => false,
                ],
            ];

            // Check if should cache
            if ($this->cacheStrategy->shouldCache($query, $result['metadata'])) {
                $ttl = $this->cacheStrategy->calculateTTL(
                    $query,
                    [], // TODO: Add usage stats
                    [
                        'confidence' => $finalConfidence,
                        'source' => $source,
                    ]
                );

                $this->cacheService->set($query, $botId, $result, $ttl);
            }

            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
            // STEP 7: Update Session Context
            // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

            if ($sessionId) {
                $this->updateSessionContext($botId, $sessionId, $query, $response['content']);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $result['metadata']['total_duration_ms'] = round($duration, 2);

            Log::info('RAG pipeline completed', [
                'query' => substr($query, 0, 50),
                'confidence' => $finalConfidence,
                'source' => $source,
                'duration_ms' => round($duration, 2),
            ]);

            return $result;

        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            Log::error('RAG pipeline failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
                'duration_ms' => round($duration, 2),
            ]);

            // Fallback response
            return [
                'answer' => "Je suis désolé, une erreur s'est produite lors du traitement de votre demande. Pouvez-vous reformuler votre question ?",
                'sources' => [],
                'confidence' => 0.0,
                'metadata' => [
                    'error' => $e->getMessage(),
                    'source' => 'error_fallback',
                    'total_duration_ms' => round($duration, 2),
                ],
            ];
        }
    }

    /**
     * Process batch queries (for warming, testing, etc.)
     *
     * @param array $queries List of queries
     * @param string $botId Bot ID
     * @param array $options Processing options
     * @return array Results for each query
     */
    public function processBatch(array $queries, string $botId, array $options = []): array
    {
        $results = [];

        foreach ($queries as $query) {
            try {
                $results[$query] = $this->process($query, $botId, null, $options);
            } catch (\Exception $e) {
                Log::error('Batch processing failed for query', [
                    'error' => $e->getMessage(),
                    'query' => substr($query, 0, 100),
                ]);

                $results[$query] = [
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // LLM GENERATION
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Generate response using LLM with retrieved context
     */
    private function generateResponse(string $query, array $context, array $sessionContext, array $options): array
    {
        // Build prompt
        $systemPrompt = $this->buildSystemPrompt($options);
        $userPrompt = $this->buildUserPrompt($query, $context, $sessionContext);

        // Call LLM
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])
        ->timeout(self::LLM_TIMEOUT)
        ->post($this->apiUrl, [
            'model' => self::LLM_MODEL,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? self::MAX_RESPONSE_TOKENS,
        ]);

        if (!$response->successful()) {
            throw new \Exception('LLM API error: ' . $response->status());
        }

        $data = $response->json();

        return [
            'content' => $data['choices'][0]['message']['content'] ?? '',
            'model' => $data['model'] ?? self::LLM_MODEL,
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * Build system prompt
     */
    private function buildSystemPrompt(array $options): string
    {
        $botName = $options['bot_name'] ?? 'Assistant';
        $personality = $options['bot_personality'] ?? 'professional and helpful';

        return <<<PROMPT
You are {$botName}, a {$personality} AI assistant.

Your role:
- Answer user questions accurately using the provided context
- Be concise and clear
- If the context doesn't contain the answer, say so honestly
- Use a professional yet friendly tone
- Cite sources when available

Guidelines:
- Prioritize factual accuracy
- Don't make up information
- If uncertain, express it
- Format responses in markdown when appropriate
PROMPT;
    }

    /**
     * Build user prompt with context
     */
    private function buildUserPrompt(string $query, array $context, array $sessionContext): string
    {
        $prompt = "# Context\n\n";

        // Add retrieved context
        if (!empty($context)) {
            $prompt .= "## Retrieved Information\n\n";

            foreach (array_slice($context, 0, 5) as $idx => $item) {
                $content = $item['content'] ?? $item['text'] ?? $item['document'] ?? '';
                $score = $item['relevance_score'] ?? $item['rerank_score'] ?? $item['score'] ?? 0;

                $prompt .= "### Source " . ($idx + 1) . " (relevance: " . round($score, 2) . ")\n";
                $prompt .= $content . "\n\n";
            }
        }

        // Add session context if available
        if (!empty($sessionContext)) {
            $prompt .= "## Session Context\n\n";
            $prompt .= json_encode($sessionContext, JSON_PRETTY_PRINT) . "\n\n";
        }

        // Add user query
        $prompt .= "# User Question\n\n";
        $prompt .= $query . "\n\n";
        $prompt .= "# Your Answer\n\n";
        $prompt .= "Based on the context above, provide a clear and accurate answer:";

        // Truncate if too long
        if (strlen($prompt) > self::MAX_CONTEXT_LENGTH * 4) { // Rough token estimation
            $prompt = substr($prompt, 0, self::MAX_CONTEXT_LENGTH * 4);
        }

        return $prompt;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Calculate final confidence score
     */
    private function calculateFinalConfidence(float $retrievalConfidence, array $context, array $response): float
    {
        // Factors:
        // 1. Retrieval confidence (how relevant was context)
        // 2. Context quality (number and quality of sources)
        // 3. Response length (too short = uncertain)

        $confidence = $retrievalConfidence;

        // Boost if multiple high-quality sources
        if (count($context) >= 3) {
            $avgScore = 0;
            foreach ($context as $item) {
                $avgScore += $item['rerank_score'] ?? $item['score'] ?? 0;
            }
            $avgScore /= count($context);

            if ($avgScore > 0.8) {
                $confidence = min($confidence * 1.2, 1.0);
            }
        }

        // Penalize if response is very short (uncertain)
        $responseLength = strlen($response['content'] ?? '');
        if ($responseLength < 50) {
            $confidence *= 0.7;
        }

        return min(max($confidence, 0.0), 1.0);
    }

    /**
     * Format sources for response
     */
    private function formatSources(array $context): array
    {
        $sources = [];

        foreach ($context as $idx => $item) {
            $sources[] = [
                'index' => $idx + 1,
                'content' => substr($item['content'] ?? $item['text'] ?? $item['document'] ?? '', 0, 200) . '...',
                'score' => $item['relevance_score'] ?? $item['rerank_score'] ?? $item['score'] ?? 0,
                'reranked' => $item['reranked'] ?? false,
                'metadata' => $item['metadata'] ?? [],
            ];
        }

        return $sources;
    }

    /**
     * Update session context in working memory
     */
    private function updateSessionContext(string $botId, string $sessionId, string $query, string $response): void
    {
        try {
            $context = $this->workingMemory->get($botId, "session:{$sessionId}") ?? [
                'messages' => [],
                'created_at' => now()->toIso8601String(),
            ];

            // Add new message pair
            $context['messages'][] = [
                'query' => $query,
                'response' => $response,
                'timestamp' => now()->toIso8601String(),
            ];

            // Keep only last 7 messages (7±2 rule)
            if (count($context['messages']) > 7) {
                $context['messages'] = array_slice($context['messages'], -7);
            }

            $context['updated_at'] = now()->toIso8601String();

            // Store with 1h TTL
            $this->workingMemory->store($botId, "session:{$sessionId}", $context, 3600);

        } catch (\Exception $e) {
            Log::warning('Failed to update session context', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }
}
