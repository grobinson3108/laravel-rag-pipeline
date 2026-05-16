# Changelog

All notable changes to `laravel-rag-pipeline` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned (v0.2)
- Driver abstraction (Pinecone, Qdrant, Memgraph)
- Reranker interface (Cohere, Voyage, BGE local, custom)
- Migrated test suite (currently only CohereRerank shipped)

### Planned (v1.0)
- Telemetry hooks (Langfuse, OTLP)
- Performance benchmarks
- Cost guards integration

## [0.1.0-alpha] — 2026-05-16

### Added
- **RAG core** (4 services): RAGOrchestratorService, EmbeddingService, CohereRerankService, RerankingService
- **Routing** (2 services): QueryClassifierService, QueryRouterService
- **Memory** (3 services): KnowledgeGraphService (Neo4j), VectorMemoryService (ChromaDB), WorkingMemoryService (Redis LRU)
- **Cache** (3 services): QueryCacheService (L1/L2/L3), CacheStrategyService, PreComputedResponseService
- `RagPipelineServiceProvider` auto-discovered by Laravel
- `config/rag-pipeline.php` publishable
- 12-scenario test for CohereRerankService
- README + LICENSE MIT

### Origin
Extracted from production code in a chatbot SaaS (Botlers V1) that handled real customer conversations for several months in 2025-2026.

[Unreleased]: https://github.com/grobinson3108/laravel-rag-pipeline/compare/v0.1.0-alpha...HEAD
[0.1.0-alpha]: https://github.com/grobinson3108/laravel-rag-pipeline/releases/tag/v0.1.0-alpha
