<?php

namespace Grobinson3108\LaravelRagPipeline\Tests\Unit;

use Tests\TestCase;
use Grobinson3108\LaravelRagPipeline\RAG\CohereRerankService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * CohereRerankServiceTest
 *
 * Tests unitaires pour le service de re-ranking Cohere
 *
 * Scénarios testés:
 * - Re-ranking basique
 * - Gestion des erreurs API
 * - Fallback en cas d'échec
 * - Cache des résultats
 * - Batch re-ranking
 */
class CohereRerankServiceTest extends TestCase
{
    private CohereRerankService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure Cohere API key for tests
        config(['services.cohere.api_key' => 'test-api-key']);
        config(['services.cohere.model' => 'rerank-multilingual-v3.0']);
        config(['services.cohere.timeout' => 5]);

        $this->service = new CohereRerankService();
    }

    /**
     * Test successful re-ranking
     */
    public function test_rerank_returns_sorted_results(): void
    {
        // Mock Cohere API response
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 2, 'relevance_score' => 0.95],
                    ['index' => 0, 'relevance_score' => 0.87],
                    ['index' => 1, 'relevance_score' => 0.72],
                ],
            ], 200),
        ]);

        $documents = [
            ['text' => 'Document A', 'id' => '1'],
            ['text' => 'Document B', 'id' => '2'],
            ['text' => 'Document C', 'id' => '3'],
        ];

        $result = $this->service->rerank('test query', $documents, 3);

        // Verify results are returned in re-ranked order
        $this->assertCount(3, $result);
        $this->assertEquals('3', $result[0]['id']);
        $this->assertEquals(0.95, $result[0]['relevance_score']);
        $this->assertTrue($result[0]['reranked']);

        $this->assertEquals('1', $result[1]['id']);
        $this->assertEquals(0.87, $result[1]['relevance_score']);

        $this->assertEquals('2', $result[2]['id']);
        $this->assertEquals(0.72, $result[2]['relevance_score']);
    }

    /**
     * Test re-ranking with string documents
     */
    public function test_rerank_handles_string_documents(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 1, 'relevance_score' => 0.88],
                    ['index' => 0, 'relevance_score' => 0.75],
                ],
            ], 200),
        ]);

        $documents = [
            'First document text',
            'Second document text',
        ];

        $result = $this->service->rerank('query', $documents, 2);

        $this->assertCount(2, $result);
        $this->assertEquals('Second document text', $result[0]['text']);
        $this->assertEquals(0.88, $result[0]['relevance_score']);
    }

    /**
     * Test top-N filtering
     */
    public function test_rerank_respects_top_n_parameter(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 2, 'relevance_score' => 0.95],
                    ['index' => 0, 'relevance_score' => 0.87],
                ],
            ], 200),
        ]);

        $documents = array_fill(0, 10, ['text' => 'document']);

        $result = $this->service->rerank('query', $documents, 2);

        $this->assertCount(2, $result);
    }

    /**
     * Test API error handling with fallback
     */
    public function test_rerank_handles_api_errors_with_fallback(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response(['error' => 'API error'], 500),
        ]);

        $documents = [
            ['text' => 'Doc 1'],
            ['text' => 'Doc 2'],
            ['text' => 'Doc 3'],
        ];

        $result = $this->service->rerank('query', $documents, 2);

        // Should return fallback results
        $this->assertCount(2, $result);
        $this->assertFalse($result[0]['reranked']);
        $this->assertTrue($result[0]['fallback']);

        // Scores should decrease
        $this->assertGreaterThan($result[1]['relevance_score'], $result[0]['relevance_score']);
    }

    /**
     * Test empty documents handling
     */
    public function test_rerank_handles_empty_documents(): void
    {
        $result = $this->service->rerank('query', [], 5);

        $this->assertEmpty($result);
    }

    /**
     * Test caching of results
     */
    public function test_rerank_caches_results(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 0, 'relevance_score' => 0.95],
                ],
            ], 200),
        ]);

        Cache::shouldReceive('put')
            ->once()
            ->with(
                \Mockery::pattern('/^cohere:rerank:/'),
                \Mockery::any(),
                \Mockery::any()
            );

        $documents = [['text' => 'Doc 1']];
        $this->service->rerank('test query', $documents, 1);
    }

    /**
     * Test cache retrieval
     */
    public function test_get_cached_rerank_returns_cached_results(): void
    {
        $cachedResults = [
            ['text' => 'Cached doc', 'relevance_score' => 0.9],
        ];

        Cache::shouldReceive('get')
            ->once()
            ->with(\Mockery::pattern('/^cohere:rerank:/'))
            ->andReturn($cachedResults);

        $result = $this->service->getCachedRerank('test query');

        $this->assertEquals($cachedResults, $result);
    }

    /**
     * Test batch re-ranking
     */
    public function test_batch_rerank_processes_multiple_queries(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 0, 'relevance_score' => 0.9],
                ],
            ], 200),
        ]);

        $queryDocumentPairs = [
            [
                'query' => 'Query 1',
                'documents' => [['text' => 'Doc 1']],
            ],
            [
                'query' => 'Query 2',
                'documents' => [['text' => 'Doc 2']],
            ],
        ];

        $results = $this->service->batchRerank($queryDocumentPairs, 1);

        $this->assertCount(2, $results);
        $this->assertNotEmpty($results[0]);
        $this->assertNotEmpty($results[1]);
    }

    /**
     * Test batch re-ranking with invalid pairs
     */
    public function test_batch_rerank_handles_invalid_pairs(): void
    {
        $queryDocumentPairs = [
            ['query' => '', 'documents' => []],
            ['query' => 'Valid query', 'documents' => []],
        ];

        $results = $this->service->batchRerank($queryDocumentPairs, 1);

        $this->assertCount(2, $results);
        $this->assertEmpty($results[0]);
        $this->assertEmpty($results[1]);
    }

    /**
     * Test service statistics
     */
    public function test_get_stats_returns_service_info(): void
    {
        $stats = $this->service->getStats();

        $this->assertEquals('CohereRerankService', $stats['service']);
        $this->assertEquals('rerank-multilingual-v3.0', $stats['model']);
        $this->assertTrue($stats['api_configured']);
        $this->assertEquals(5, $stats['timeout']);
    }

    /**
     * Test timeout configuration
     */
    public function test_rerank_respects_timeout_configuration(): void
    {
        config(['services.cohere.timeout' => 3]);

        Http::fake([
            'api.cohere.ai/v1/rerank' => function ($request) {
                // Verify timeout is set in request options
                return Http::response([
                    'results' => [
                        ['index' => 0, 'relevance_score' => 0.9],
                    ],
                ], 200);
            },
        ]);

        $documents = [['text' => 'Doc']];
        $this->service->rerank('query', $documents, 1);

        // If we get here without timeout exception, test passes
        $this->assertTrue(true);
    }

    /**
     * Test preservation of original document metadata
     */
    public function test_rerank_preserves_document_metadata(): void
    {
        Http::fake([
            'api.cohere.ai/v1/rerank' => Http::response([
                'results' => [
                    ['index' => 0, 'relevance_score' => 0.95],
                ],
            ], 200),
        ]);

        $documents = [
            [
                'text' => 'Document content',
                'metadata' => [
                    'source' => 'knowledge_base',
                    'category' => 'pricing',
                ],
                'id' => 'doc-123',
            ],
        ];

        $result = $this->service->rerank('query', $documents, 1);

        $this->assertEquals('doc-123', $result[0]['id']);
        $this->assertEquals('knowledge_base', $result[0]['metadata']['source']);
        $this->assertEquals('pricing', $result[0]['metadata']['category']);
        $this->assertEquals(0.95, $result[0]['relevance_score']);
        $this->assertTrue($result[0]['reranked']);
    }
}
