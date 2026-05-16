<?php

namespace Grobinson3108\LaravelRagPipeline\Memory;

use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge Graph Service (Neo4j)
 *
 * Implements semantic memory through knowledge graphs
 * - Entity-Relationship modeling
 * - Trust-weighted knowledge
 * - Graph traversal for context retrieval
 * - Consolidation of learning patterns
 */
class KnowledgeGraphService
{
    protected ClientInterface $client;
    protected string $database = 'neo4j';

    public function __construct()
    {
        $this->client = ClientBuilder::create()
            ->withDriver(
                'bolt',
                sprintf(
                    'bolt://%s:%s@%s:%s',
                    config('services.neo4j.username', 'neo4j'),
                    config('services.neo4j.password'),
                    config('services.neo4j.host', '127.0.0.1'),
                    config('services.neo4j.port', 7687)
                )
            )
            ->withDefaultDriver('bolt')
            ->build();
    }

    /**
     * Create a knowledge node
     *
     * @param string $botId
     * @param string $label
     * @param array $properties
     * @return string|null Node ID
     */
    public function createNode(string $botId, string $label, array $properties): ?string
    {
        try {
            $properties['bot_id'] = $botId;
            $properties['created_at'] = now()->toIso8601String();
            $properties['updated_at'] = now()->toIso8601String();

            $query = "
                CREATE (n:$label \$properties)
                RETURN elementId(n) as id
            ";

            $result = $this->client->run($query, ['properties' => $properties]);

            $nodeId = $result->first()->get('id');

            Log::debug("KnowledgeGraph: Node created", [
                'bot_id' => $botId,
                'label' => $label,
                'node_id' => $nodeId
            ]);

            return $nodeId;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Create node failed", [
                'bot_id' => $botId,
                'label' => $label,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Create a relationship between two nodes
     *
     * @param string $fromNodeId
     * @param string $toNodeId
     * @param string $relationshipType
     * @param array $properties
     * @return bool
     */
    public function createRelationship(
        string $fromNodeId,
        string $toNodeId,
        string $relationshipType,
        array $properties = []
    ): bool {
        try {
            $properties['created_at'] = now()->toIso8601String();

            $query = "
                MATCH (from), (to)
                WHERE elementId(from) = \$fromId AND elementId(to) = \$toId
                CREATE (from)-[r:$relationshipType \$properties]->(to)
                RETURN r
            ";

            $this->client->run($query, [
                'fromId' => $fromNodeId,
                'toId' => $toNodeId,
                'properties' => $properties
            ]);

            Log::debug("KnowledgeGraph: Relationship created", [
                'from' => $fromNodeId,
                'to' => $toNodeId,
                'type' => $relationshipType
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Create relationship failed", [
                'from' => $fromNodeId,
                'to' => $toNodeId,
                'type' => $relationshipType,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Find nodes by label and properties
     *
     * @param string $botId
     * @param string $label
     * @param array $properties
     * @return array
     */
    public function findNodes(string $botId, string $label, array $properties = []): array
    {
        try {
            $whereClauses = ["n.bot_id = \$bot_id"];
            $params = ['bot_id' => $botId];

            foreach ($properties as $key => $value) {
                $whereClauses[] = "n.$key = \$$key";
                $params[$key] = $value;
            }

            $whereClause = implode(' AND ', $whereClauses);

            $query = "
                MATCH (n:$label)
                WHERE $whereClause
                RETURN n, elementId(n) as id
                ORDER BY n.updated_at DESC
            ";

            $result = $this->client->run($query, $params);

            $nodes = [];
            foreach ($result as $record) {
                $node = $record->get('n')->getProperties();
                $node['id'] = $record->get('id');
                $nodes[] = $node;
            }

            return $nodes;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Find nodes failed", [
                'bot_id' => $botId,
                'label' => $label,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get node with its relationships
     *
     * @param string $nodeId
     * @param int $depth Traversal depth
     * @return array|null
     */
    public function getNodeWithRelationships(string $nodeId, int $depth = 1): ?array
    {
        try {
            $query = "
                MATCH (n)
                WHERE elementId(n) = \$nodeId
                OPTIONAL MATCH path = (n)-[r*1..$depth]-(related)
                RETURN n, elementId(n) as id,
                       collect({
                           relationship: type(last(r)),
                           node: related,
                           nodeId: elementId(related),
                           trustLevel: last(r).trust_level
                       }) as relationships
            ";

            $result = $this->client->run($query, ['nodeId' => $nodeId]);

            if ($result->count() === 0) {
                return null;
            }

            $record = $result->first();
            $node = $record->get('n')->getProperties();
            $node['id'] = $record->get('id');
            $node['relationships'] = [];

            foreach ($record->get('relationships') as $rel) {
                if ($rel['node'] !== null) {
                    $node['relationships'][] = [
                        'type' => $rel['relationship'],
                        'node' => $rel['node']->getProperties(),
                        'node_id' => $rel['nodeId'],
                        'trust_level' => $rel['trustLevel'] ?? 0
                    ];
                }
            }

            return $node;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Get node with relationships failed", [
                'node_id' => $nodeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Update node properties
     *
     * @param string $nodeId
     * @param array $properties
     * @return bool
     */
    public function updateNode(string $nodeId, array $properties): bool
    {
        try {
            $properties['updated_at'] = now()->toIso8601String();

            $query = "
                MATCH (n)
                WHERE elementId(n) = \$nodeId
                SET n += \$properties
                RETURN n
            ";

            $this->client->run($query, [
                'nodeId' => $nodeId,
                'properties' => $properties
            ]);

            Log::debug("KnowledgeGraph: Node updated", ['node_id' => $nodeId]);

            return true;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Update node failed", [
                'node_id' => $nodeId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete a node and its relationships
     *
     * @param string $nodeId
     * @return bool
     */
    public function deleteNode(string $nodeId): bool
    {
        try {
            $query = "
                MATCH (n)
                WHERE elementId(n) = \$nodeId
                DETACH DELETE n
            ";

            $this->client->run($query, ['nodeId' => $nodeId]);

            Log::info("KnowledgeGraph: Node deleted", ['node_id' => $nodeId]);

            return true;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Delete node failed", [
                'node_id' => $nodeId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Clear all knowledge for a bot
     *
     * @param string $botId
     * @return bool
     */
    public function clearBotKnowledge(string $botId): bool
    {
        try {
            $query = "
                MATCH (n {bot_id: \$bot_id})
                DETACH DELETE n
            ";

            $this->client->run($query, ['bot_id' => $botId]);

            Log::warning("KnowledgeGraph: Bot knowledge cleared", ['bot_id' => $botId]);

            return true;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Clear bot knowledge failed", [
                'bot_id' => $botId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Execute a custom Cypher query
     *
     * @param string $query
     * @param array $parameters
     * @return array
     */
    public function query(string $query, array $parameters = []): array
    {
        try {
            $result = $this->client->run($query, $parameters);

            $results = [];
            foreach ($result as $record) {
                $results[] = $record->toArray();
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Custom query failed", [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Health check for Neo4j connection
     *
     * @return bool
     */
    public function healthCheck(): bool
    {
        try {
            $result = $this->client->run('RETURN 1 as test');
            return $result->first()->get('test') === 1;
        } catch (\Exception $e) {
            Log::error("KnowledgeGraph: Health check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
