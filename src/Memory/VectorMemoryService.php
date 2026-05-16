<?php

namespace Grobinson3108\LaravelRagPipeline\Memory;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Vector Memory Service (ChromaDB)
 *
 * Implements semantic memory through vector embeddings
 * - Store document embeddings
 * - Similarity search
 * - Collection management
 * - Batch operations
 */
class VectorMemoryService
{
    protected Client $client;
    protected string $baseUrl;
    protected string $tenant;
    protected string $database;

    public function __construct()
    {
        $this->baseUrl = config('services.chromadb.url', 'http://127.0.0.1:8002');
        $this->tenant = config('services.chromadb.tenant', 'default_tenant');
        $this->database = config('services.chromadb.database', 'default_database');

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * Create or get a collection
     *
     * @param string $botId
     * @param string $collectionName
     * @param array $metadata
     * @return array|null Collection info
     */
    public function createCollection(string $botId, string $collectionName = 'knowledge', array $metadata = []): ?array
    {
        try {
            $fullName = $this->buildCollectionName($botId, $collectionName);

            $response = $this->client->post($this->buildEndpoint(), [
                'json' => [
                    'name' => $fullName,
                    'metadata' => array_merge($metadata, [
                        'bot_id' => $botId,
                        'created_at' => now()->toIso8601String()
                    ])
                ]
            ]);

            $collection = json_decode($response->getBody()->getContents(), true);

            Log::info("VectorMemory: Collection created", [
                'bot_id' => $botId,
                'collection' => $fullName
            ]);

            return $collection;
        } catch (GuzzleException $e) {
            // Collection might already exist
            if (str_contains($e->getMessage(), 'already exists')) {
                Log::debug("VectorMemory: Collection already exists", [
                    'bot_id' => $botId,
                    'collection' => $fullName
                ]);
                return $this->getCollection($botId, $collectionName);
            }

            Log::error("VectorMemory: Create collection failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get collection info by name
     *
     * @param string $botId
     * @param string $collectionName
     * @return array|null
     */
    public function getCollection(string $botId, string $collectionName = 'knowledge'): ?array
    {
        try {
            $fullName = $this->buildCollectionName($botId, $collectionName);

            // List all collections and find by name
            $response = $this->client->get($this->buildEndpoint());
            $collections = json_decode($response->getBody()->getContents(), true);

            foreach ($collections as $collection) {
                if ($collection['name'] === $fullName) {
                    return $collection;
                }
            }

            return null;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Get collection failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Add documents with embeddings to collection
     *
     * @param string $botId
     * @param array $documents Array of document texts
     * @param array $embeddings Array of embedding vectors
     * @param array $metadatas Array of metadata objects
     * @param array $ids Array of document IDs
     * @param string $collectionName
     * @return bool
     */
    public function addDocuments(
        string $botId,
        array $documents,
        array $embeddings,
        array $metadatas = [],
        array $ids = [],
        string $collectionName = 'knowledge'
    ): bool {
        try {
            // Generate IDs if not provided
            if (empty($ids)) {
                $ids = array_map(fn($i) => uniqid("doc_{$i}_", true), array_keys($documents));
            }

            // Add bot_id to all metadatas
            $metadatas = array_map(function($meta) use ($botId) {
                return array_merge($meta ?? [], ['bot_id' => $botId]);
            }, $metadatas ?: array_fill(0, count($documents), []));

            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                throw new \Exception("Collection not found: {$collectionName}");
            }

            $response = $this->client->post($this->buildEndpoint($collectionId, 'add'), [
                'json' => [
                    'ids' => $ids,
                    'documents' => $documents,
                    'embeddings' => $embeddings,
                    'metadatas' => $metadatas
                ]
            ]);

            Log::debug("VectorMemory: Documents added", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'count' => count($documents)
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Add documents failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'count' => count($documents),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Query collection with embedding vector (similarity search)
     *
     * @param string $botId
     * @param array $queryEmbedding Query vector
     * @param int $nResults Number of results to return
     * @param array $where Metadata filters
     * @param string $collectionName
     * @return array Results with distances
     */
    public function query(
        string $botId,
        array $queryEmbedding,
        int $nResults = 5,
        array $where = [],
        string $collectionName = 'knowledge'
    ): array {
        try {
            // Add bot_id filter
            $where = array_merge($where, ['bot_id' => $botId]);

            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                throw new \Exception("Collection not found: {$collectionName}");
            }

            $response = $this->client->post($this->buildEndpoint($collectionId, 'query'), [
                'json' => [
                    'query_embeddings' => [$queryEmbedding],
                    'n_results' => $nResults,
                    'where' => $where,
                    'include' => ['documents', 'metadatas', 'distances']
                ]
            ]);

            $results = json_decode($response->getBody()->getContents(), true);

            Log::debug("VectorMemory: Query executed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'n_results' => $nResults,
                'found' => count($results['ids'][0] ?? [])
            ]);

            // Flatten results (ChromaDB returns nested arrays)
            return [
                'ids' => $results['ids'][0] ?? [],
                'documents' => $results['documents'][0] ?? [],
                'metadatas' => $results['metadatas'][0] ?? [],
                'distances' => $results['distances'][0] ?? []
            ];
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Query failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return [
                'ids' => [],
                'documents' => [],
                'metadatas' => [],
                'distances' => []
            ];
        }
    }

    /**
     * Get documents by IDs
     *
     * @param string $botId
     * @param array $ids Document IDs
     * @param string $collectionName
     * @return array
     */
    public function getDocuments(string $botId, array $ids, string $collectionName = 'knowledge'): array
    {
        try {
            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                throw new \Exception("Collection not found: {$collectionName}");
            }

            $response = $this->client->post($this->buildEndpoint($collectionId, 'get'), [
                'json' => [
                    'ids' => $ids,
                    'include' => ['documents', 'metadatas', 'embeddings']
                ]
            ]);

            $results = json_decode($response->getBody()->getContents(), true);

            return [
                'ids' => $results['ids'] ?? [],
                'documents' => $results['documents'] ?? [],
                'metadatas' => $results['metadatas'] ?? [],
                'embeddings' => $results['embeddings'] ?? []
            ];
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Get documents failed", [
                'bot_id' => $botId,
                'ids' => $ids,
                'error' => $e->getMessage()
            ]);
            return [
                'ids' => [],
                'documents' => [],
                'metadatas' => [],
                'embeddings' => []
            ];
        }
    }

    /**
     * Update documents
     *
     * @param string $botId
     * @param array $ids
     * @param array $documents
     * @param array $embeddings
     * @param array $metadatas
     * @param string $collectionName
     * @return bool
     */
    public function updateDocuments(
        string $botId,
        array $ids,
        array $documents = [],
        array $embeddings = [],
        array $metadatas = [],
        string $collectionName = 'knowledge'
    ): bool {
        try {
            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                throw new \Exception("Collection not found: {$collectionName}");
            }

            $payload = ['ids' => $ids];

            if (!empty($documents)) {
                $payload['documents'] = $documents;
            }
            if (!empty($embeddings)) {
                $payload['embeddings'] = $embeddings;
            }
            if (!empty($metadatas)) {
                $payload['metadatas'] = $metadatas;
            }

            $response = $this->client->post($this->buildEndpoint($collectionId, 'update'), [
                'json' => $payload
            ]);

            Log::debug("VectorMemory: Documents updated", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'count' => count($ids)
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Update documents failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete documents by IDs
     *
     * @param string $botId
     * @param array $ids
     * @param string $collectionName
     * @return bool
     */
    public function deleteDocuments(string $botId, array $ids, string $collectionName = 'knowledge'): bool
    {
        try {
            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                throw new \Exception("Collection not found: {$collectionName}");
            }

            $response = $this->client->post($this->buildEndpoint($collectionId, 'delete'), [
                'json' => [
                    'ids' => $ids
                ]
            ]);

            Log::info("VectorMemory: Documents deleted", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'count' => count($ids)
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Delete documents failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete entire collection
     *
     * @param string $botId
     * @param string $collectionName
     * @return bool
     */
    public function deleteCollection(string $botId, string $collectionName = 'knowledge'): bool
    {
        try {
            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                Log::warning("VectorMemory: Collection not found for deletion", [
                    'bot_id' => $botId,
                    'collection' => $collectionName
                ]);
                return true; // Already deleted
            }

            $response = $this->client->delete($this->buildEndpoint($collectionId));

            Log::warning("VectorMemory: Collection deleted", [
                'bot_id' => $botId,
                'collection' => $collectionName
            ]);

            return true;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Delete collection failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Count documents in collection
     *
     * @param string $botId
     * @param string $collectionName
     * @return int
     */
    public function count(string $botId, string $collectionName = 'knowledge'): int
    {
        try {
            $collectionId = $this->getCollectionId($botId, $collectionName);
            if (!$collectionId) {
                return 0; // Collection doesn't exist
            }

            $response = $this->client->get($this->buildEndpoint($collectionId, 'count'));

            $count = (int) $response->getBody()->getContents();

            return $count;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Count failed", [
                'bot_id' => $botId,
                'collection' => $collectionName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get collection ID by name
     *
     * @param string $botId
     * @param string $collectionName
     * @return string|null
     */
    protected function getCollectionId(string $botId, string $collectionName): ?string
    {
        $collection = $this->getCollection($botId, $collectionName);
        return $collection['id'] ?? null;
    }

    /**
     * Build API endpoint for collections
     *
     * @param string|null $collectionId
     * @param string|null $operation
     * @return string
     */
    protected function buildEndpoint(?string $collectionId = null, ?string $operation = null): string
    {
        $endpoint = "/api/v2/tenants/{$this->tenant}/databases/{$this->database}/collections";

        if ($collectionId) {
            $endpoint .= "/{$collectionId}";

            if ($operation) {
                $endpoint .= "/{$operation}";
            }
        }

        return $endpoint;
    }

    /**
     * Build full collection name (namespaced by bot)
     *
     * @param string $botId
     * @param string $collectionName
     * @return string
     */
    protected function buildCollectionName(string $botId, string $collectionName): string
    {
        // ChromaDB collection names: lowercase, alphanumeric + - _ .
        $safeBotId = preg_replace('/[^a-z0-9\-_.]/', '_', strtolower($botId));
        $safeName = preg_replace('/[^a-z0-9\-_.]/', '_', strtolower($collectionName));

        return "rag_{$safeBotId}_{$safeName}";
    }

    /**
     * Health check for ChromaDB connection
     *
     * @return bool
     */
    public function healthCheck(): bool
    {
        try {
            $response = $this->client->get('/api/v2/heartbeat');
            $result = json_decode($response->getBody()->getContents(), true);

            // ChromaDB heartbeat returns nanoseconds timestamp
            return isset($result['nanosecond heartbeat']) || $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error("VectorMemory: Health check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
