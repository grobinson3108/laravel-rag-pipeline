<?php

namespace Grobinson3108\LaravelRagPipeline\Router;

use Illuminate\Support\Facades\Log;
use Grobinson3108\LaravelRagPipeline\Memory\KnowledgeGraphService;
use Grobinson3108\LaravelRagPipeline\Memory\VectorMemoryService;
use Grobinson3108\LaravelRagPipeline\RAG\EmbeddingService;
use Grobinson3108\LaravelRagPipeline\RAG\RerankingService;

/**
 * QueryRouterService
 *
 * Route les requêtes vers le bon service selon classification:
 * - STRUCTURED → Knowledge Graph (Neo4j)
 * - SEMANTIC → Vector RAG (ChromaDB)
 * - COMPLEX → LLM direct (via orchestrator)
 *
 * Gestion intelligente des fallbacks:
 * - Si KG vide → fallback vers Vector RAG
 * - Si Vector RAG vide → fallback vers LLM
 * - Multi-source hybride possible
 *
 * Objectif: >90% success rate, <100ms latence
 */
class QueryRouterService
{
    private QueryClassifierService $classifier;
    private KnowledgeGraphService $knowledgeGraph;
    private VectorMemoryService $vectorMemory;
    private EmbeddingService $embeddingService;
    private RerankingService $rerankingService;

    private const MAX_VECTOR_RESULTS = 10;
    private const MAX_GRAPH_DEPTH = 2;
    private const MIN_CONFIDENCE_THRESHOLD = 0.5;

    public function __construct(
        QueryClassifierService $classifier,
        KnowledgeGraphService $knowledgeGraph,
        VectorMemoryService $vectorMemory,
        EmbeddingService $embeddingService,
        RerankingService $rerankingService
    ) {
        $this->classifier = $classifier;
        $this->knowledgeGraph = $knowledgeGraph;
        $this->vectorMemory = $vectorMemory;
        $this->embeddingService = $embeddingService;
        $this->rerankingService = $rerankingService;
    }

    /**
     * Route query to appropriate service(s)
     *
     * @param string $query User query
     * @param string $botId Bot ID
     * @param array $options Routing options
     * @return array ['results' => array, 'source' => string, 'confidence' => float, 'metadata' => array]
     */
    public function route(string $query, string $botId, array $options = []): array
    {
        $startTime = microtime(true);

        // Classify query
        $classification = $this->classifier->explainClassification($query);
        $type = $classification['type'];
        $classificationConfidence = $classification['confidence'];

        Log::info('Routing query', [
            'query' => substr($query, 0, 50),
            'type' => $type,
            'confidence' => $classificationConfidence,
            'reasoning' => $classification['reasoning'] ?? 'N/A',
        ]);

        // Route based on classification
        $result = match ($type) {
            QueryClassifierService::TYPE_STRUCTURED => $this->routeToStructured($query, $botId, $options),
            QueryClassifierService::TYPE_SEMANTIC => $this->routeToSemantic($query, $botId, $options),
            QueryClassifierService::TYPE_COMPLEX => $this->routeToComplex($query, $botId, $options),
            default => $this->routeToSemantic($query, $botId, $options), // Default fallback
        };

        // Apply fallbacks if needed
        if ($this->shouldFallback($result, $options)) {
            $result = $this->applyFallback($query, $botId, $type, $result, $options);
        }

        // Add metadata
        $duration = (microtime(true) - $startTime) * 1000;
        $result['metadata'] = array_merge($result['metadata'] ?? [], [
            'classification_type' => $type,
            'classification_confidence' => $classificationConfidence,
            'classification_reasoning' => $classification['reasoning'] ?? 'N/A',
            'routing_duration_ms' => round($duration, 2),
        ]);

        Log::info('Query routed', [
            'query' => substr($query, 0, 50),
            'source' => $result['source'],
            'confidence' => $result['confidence'],
            'results_count' => count($result['results']),
            'duration_ms' => round($duration, 2),
        ]);

        return $result;
    }

    /**
     * Route to multiple sources (hybrid approach)
     *
     * @param string $query User query
     * @param string $botId Bot ID
     * @param array $sources Sources to query ['graph', 'vector', 'llm']
     * @return array Combined results
     */
    public function routeMulti(string $query, string $botId, array $sources = ['graph', 'vector']): array
    {
        $startTime = microtime(true);
        $allResults = [];
        $sourceResults = [];

        Log::info('Multi-source routing', [
            'query' => substr($query, 0, 50),
            'sources' => $sources,
        ]);

        // Query each source
        foreach ($sources as $source) {
            try {
                $result = match ($source) {
                    'graph' => $this->routeToStructured($query, $botId),
                    'vector' => $this->routeToSemantic($query, $botId),
                    'llm' => $this->routeToComplex($query, $botId),
                    default => null,
                };

                if ($result && !empty($result['results'])) {
                    $sourceResults[$source] = $result;
                    $allResults = array_merge($allResults, $result['results']);
                }

            } catch (\Exception $e) {
                Log::warning("Multi-source routing failed for {$source}", [
                    'error' => $e->getMessage(),
                    'query' => substr($query, 0, 100),
                ]);
            }
        }

        // Deduplicate and rerank
        $allResults = $this->deduplicateResults($allResults);
        $allResults = $this->rerankingService->rerank($query, $allResults, 10);

        $duration = (microtime(true) - $startTime) * 1000;

        return [
            'results' => $allResults,
            'source' => 'hybrid_' . implode('_', $sources),
            'confidence' => $this->calculateHybridConfidence($allResults),
            'metadata' => [
                'sources' => $sourceResults,
                'total_results' => count($allResults),
                'duration_ms' => round($duration, 2),
            ],
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ROUTING STRATEGIES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Route to STRUCTURED source (Knowledge Graph)
     */
    private function routeToStructured(string $query, string $botId, array $options = []): array
    {
        try {
            // Extract entities from query (simple keyword extraction for now)
            $keywords = $this->extractKeywords($query);

            if (empty($keywords)) {
                return [
                    'results' => [],
                    'source' => 'knowledge_graph',
                    'confidence' => 0.0,
                ];
            }

            // Search in Knowledge Graph
            $results = [];

            foreach ($keywords as $keyword) {
                // Find nodes matching keyword
                $nodes = $this->knowledgeGraph->findNodes(
                    $botId,
                    'Entity', // Generic label, could be more specific
                    ['name' => $keyword]
                );

                if (!empty($nodes)) {
                    foreach ($nodes as $node) {
                        // Get node with relationships
                        $nodeWithRel = $this->knowledgeGraph->getNodeWithRelationships(
                            $node['id'],
                            $options['graph_depth'] ?? self::MAX_GRAPH_DEPTH
                        );

                        if ($nodeWithRel) {
                            $results[] = [
                                'content' => $this->formatGraphResult($nodeWithRel),
                                'metadata' => [
                                    'node_id' => $nodeWithRel['id'],
                                    'label' => $nodeWithRel['label'],
                                    'properties' => $nodeWithRel['properties'],
                                ],
                                'score' => 0.9, // High confidence for exact matches
                            ];
                        }
                    }
                }
            }

            return [
                'results' => array_slice($results, 0, $options['max_results'] ?? 10),
                'source' => 'knowledge_graph',
                'confidence' => !empty($results) ? 0.85 : 0.0,
            ];

        } catch (\Exception $e) {
            Log::error('Knowledge Graph routing failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return [
                'results' => [],
                'source' => 'knowledge_graph',
                'confidence' => 0.0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Route to SEMANTIC source (Vector RAG)
     */
    private function routeToSemantic(string $query, string $botId, array $options = []): array
    {
        try {
            // Generate query embedding
            $queryEmbedding = $this->embeddingService->generateEmbedding($query);

            // Query vector memory
            $results = $this->vectorMemory->query(
                $botId,
                $queryEmbedding,
                $options['max_results'] ?? self::MAX_VECTOR_RESULTS,
                $options['where'] ?? []
            );

            if (empty($results)) {
                return [
                    'results' => [],
                    'source' => 'vector_rag',
                    'confidence' => 0.0,
                ];
            }

            // Rerank results
            $reranked = $this->rerankingService->rerank(
                $query,
                $results,
                $options['top_k'] ?? 5
            );

            // Calculate confidence (average rerank score)
            $confidence = 0.0;
            if (!empty($reranked)) {
                $totalScore = array_sum(array_column($reranked, 'rerank_score'));
                $confidence = $totalScore / count($reranked);
            }

            return [
                'results' => $reranked,
                'source' => 'vector_rag',
                'confidence' => $confidence,
            ];

        } catch (\Exception $e) {
            Log::error('Vector RAG routing failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
            ]);

            return [
                'results' => [],
                'source' => 'vector_rag',
                'confidence' => 0.0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Route to COMPLEX source (LLM direct)
     */
    private function routeToComplex(string $query, string $botId, array $options = []): array
    {
        // COMPLEX queries need orchestration layer (RAGOrchestratorService)
        // For now, return marker for orchestrator to handle

        return [
            'results' => [],
            'source' => 'llm_direct',
            'confidence' => 0.0,
            'requires_orchestration' => true,
            'metadata' => [
                'reason' => 'Complex query requires LLM orchestration',
            ],
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // FALLBACK STRATEGIES
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Check if fallback is needed
     */
    private function shouldFallback(array $result, array $options): bool
    {
        // No results
        if (empty($result['results'])) {
            return true;
        }

        // Low confidence
        if (isset($result['confidence']) && $result['confidence'] < self::MIN_CONFIDENCE_THRESHOLD) {
            return true;
        }

        // Error occurred
        if (isset($result['error'])) {
            return true;
        }

        // Explicitly disabled
        if (isset($options['no_fallback']) && $options['no_fallback']) {
            return false;
        }

        return false;
    }

    /**
     * Apply fallback routing
     */
    private function applyFallback(string $query, string $botId, string $originalType, array $originalResult, array $options): array
    {
        Log::info('Applying fallback', [
            'query' => substr($query, 0, 50),
            'original_type' => $originalType,
            'original_source' => $originalResult['source'],
        ]);

        // Fallback chain:
        // STRUCTURED → SEMANTIC → COMPLEX
        // SEMANTIC → COMPLEX
        // COMPLEX → SEMANTIC (reverse fallback)

        if ($originalType === QueryClassifierService::TYPE_STRUCTURED) {
            // Try Vector RAG
            $fallbackResult = $this->routeToSemantic($query, $botId, $options);

            if (!$this->shouldFallback($fallbackResult, ['no_fallback' => true])) {
                $fallbackResult['metadata']['fallback_from'] = 'knowledge_graph';
                return $fallbackResult;
            }

            // If still empty, return marker for LLM
            return $this->routeToComplex($query, $botId, $options);
        }

        if ($originalType === QueryClassifierService::TYPE_SEMANTIC) {
            // Try LLM orchestration
            $fallbackResult = $this->routeToComplex($query, $botId, $options);
            $fallbackResult['metadata']['fallback_from'] = 'vector_rag';
            return $fallbackResult;
        }

        if ($originalType === QueryClassifierService::TYPE_COMPLEX) {
            // Reverse fallback: Try Vector RAG
            $fallbackResult = $this->routeToSemantic($query, $botId, $options);
            $fallbackResult['metadata']['fallback_from'] = 'llm_direct';
            return $fallbackResult;
        }

        // No fallback possible
        return $originalResult;
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // HELPERS
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

    /**
     * Extract keywords from query (simple implementation)
     */
    private function extractKeywords(string $query): array
    {
        // Remove common stop words
        $stopWords = ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'est', 'sont', 'quel', 'quelle', 'quels', 'quelles'];

        $words = preg_split('/\s+/', mb_strtolower($query));
        $keywords = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords) && !preg_match('/[?!.,;:]/', $word);
        });

        return array_values($keywords);
    }

    /**
     * Format Knowledge Graph result for display
     */
    private function formatGraphResult(array $node): string
    {
        $name = $node['properties']['name'] ?? 'N/A';
        $content = "**{$node['label']}**: {$name}\n\n";

        if (!empty($node['properties'])) {
            foreach ($node['properties'] as $key => $value) {
                if ($key !== 'name') {
                    $content .= "- **{$key}**: {$value}\n";
                }
            }
        }

        if (!empty($node['relationships'])) {
            $content .= "\n**Relations**:\n";
            foreach ($node['relationships'] as $rel) {
                $name = $rel['target']['properties']['name'] ?? 'N/A';
                $content .= "- {$rel['type']}: {$rel['target']['label']} ({$name})\n";
            }
        }

        return $content;
    }

    /**
     * Deduplicate results (remove similar content)
     */
    private function deduplicateResults(array $results): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($results as $result) {
            $content = $result['content'] ?? $result['document'] ?? '';
            $hash = hash('sha256', $content);

            if (!in_array($hash, $seen)) {
                $seen[] = $hash;
                $deduplicated[] = $result;
            }
        }

        return $deduplicated;
    }

    /**
     * Calculate hybrid confidence from multiple sources
     */
    private function calculateHybridConfidence(array $results): float
    {
        if (empty($results)) {
            return 0.0;
        }

        // Average of rerank scores
        $scores = array_column($results, 'rerank_score');
        if (empty($scores)) {
            return 0.5; // Default
        }

        return array_sum($scores) / count($scores);
    }
}
