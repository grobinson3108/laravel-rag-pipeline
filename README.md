<div align="center">

# 🧠 Laravel RAG Pipeline

**Production-grade Retrieval-Augmented Generation for Laravel.**

Dense vector search · Knowledge graph · Cohere reranking · Multi-tier semantic cache · LLM query classifier.

Extracted from a chatbot SaaS in production.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-777BB4.svg)](https://www.php.net/)
[![Laravel 11/12/13](https://img.shields.io/badge/Laravel-11_|_12_|_13-FF2D20.svg)](https://laravel.com/)
[![Status: alpha](https://img.shields.io/badge/status-alpha%20v0.1-orange)](#status)

</div>

---

## ⚠️ Status: alpha v0.1

This package was extracted from production code [^1] and ships a working but unpolished v0.1. Expect:

- ✅ All 12 services compile, namespaces clean, no leaked secrets
- ✅ Configuration via standard Laravel `config/rag-pipeline.php`
- ✅ Drop-in `RagPipelineServiceProvider` auto-discovered by Laravel
- ⚠️ Tests partially migrated (CohereRerankService 12 scenarios shipped, others need adaptation)
- ⚠️ Examples coming in v0.2
- ⚠️ Vector store and graph DB hard-coded to ChromaDB + Neo4j (driver abstraction planned)

If you want polish before betting your project on it, watch the repo and wait for v1.0. If you want to use it now and shape the API, jump in — PRs and issues very welcome.

[^1]: Specifically: a chatbot SaaS that handled real customer conversations with this exact pipeline for several months in 2025–2026.

---

## What this gives you

A single Laravel package that handles **the full RAG pipeline** end-to-end — not just vector search, but everything between *"user types a question"* and *"LLM gets the best context"*.

```
                     ┌──────────────────────────────────────┐
   user query  ─────▶│  QueryClassifierService              │
                     │  STRUCTURED | SEMANTIC | COMPLEX     │
                     └──────────────┬───────────────────────┘
                                    │
              ┌─────────────────────┴─────────────────────┐
              ▼                                            ▼
   ┌─────────────────────┐                  ┌──────────────────────┐
   │  QueryCacheService  │  L1 / L2 / L3    │  QueryRouterService  │
   │  exact / norm /     │  cache miss      │  routes to dense /   │
   │  semantic similarity│ ───────────────▶ │  graph / hybrid      │
   └─────────────────────┘                  └─────────┬────────────┘
                                                      │
                ┌─────────────────────┬───────────────┴───────────────┐
                ▼                     ▼                               ▼
   ┌──────────────────┐   ┌────────────────────┐         ┌──────────────────┐
   │ EmbeddingService │   │KnowledgeGraph      │         │VectorMemoryService│
   │ OpenAI embed-3   │   │Service (Neo4j)     │         │ ChromaDB v2 API  │
   │ + 30d cache      │   │ Cypher queries     │         │ Guzzle direct    │
   └────────┬─────────┘   └────────┬───────────┘         └────────┬─────────┘
            │                      │                              │
            └──────────────────────┼──────────────────────────────┘
                                   ▼
                     ┌─────────────────────────────┐
                     │  CohereRerankService        │
                     │  rerank-multilingual-v3.0   │
                     │  (fallback: RerankingService│
                     │   heuristic + OpenAI score) │
                     └──────────────┬──────────────┘
                                    │
                                    ▼
                     ┌─────────────────────────────┐
                     │  WorkingMemoryService       │
                     │  Redis LRU 7±2 items        │
                     │  (Miller's law)             │
                     └──────────────┬──────────────┘
                                    │
                                    ▼
                              top-K chunks
                          ready for your LLM
```

12 services, ~3,500 lines of PHP, all wired through one `RAGOrchestratorService`.

---

## What's rare about this

The PHP / Laravel ecosystem has plenty of *vector-search-and-stop-there* tutorials. What you usually don't get bundled:

| Component | Status in most PHP RAG examples | In this package |
|-----------|:------------------------------:|:----------------:|
| Dense vector search (ChromaDB) | ✅ | ✅ |
| Embedding cache (30d) | ❌ | ✅ |
| Knowledge graph (Neo4j) | ❌ | ✅ |
| Multi-source query router | ❌ | ✅ |
| LLM-based query classifier | ❌ | ✅ |
| Cohere reranking | ❌ | ✅ |
| Fallback local reranker | ❌ | ✅ |
| L1/L2/L3 semantic cache | ❌ | ✅ |
| Pre-computed FAQ responses | ❌ | ✅ |
| Working memory (Miller's 7±2) | ❌ | ✅ |

If you've been gluing 4 different libraries to get this stack, that's the gap.

---

## Quick start

```bash
composer require grobinson3108/laravel-rag-pipeline:dev-main
```

Publish config:

```bash
php artisan vendor:publish --tag=rag-pipeline-config
```

Fill `.env`:

```env
OPENAI_API_KEY=sk-...
COHERE_API_KEY=...
CHROMA_HOST=http://localhost:8000
NEO4J_URI=bolt://localhost:7687
NEO4J_USER=neo4j
NEO4J_PASSWORD=your-password
```

Use the orchestrator:

```php
use Grobinson3108\LaravelRagPipeline\RAG\RAGOrchestratorService;

$rag = app(RAGOrchestratorService::class);

$result = $rag->process(
    query: 'How do I cancel my subscription?',
    botId: 'my-bot-uuid',
    sessionId: $request->session()->getId(),
    options: ['top_k' => 5]
);

// $result['contexts'] => array of top-K chunks with scores
// $result['metadata']  => timing, cache hits, classifier verdict, etc.
```

---

## Services exposed

All bound as singletons in the container — inject anywhere.

| Service | Role |
|---------|------|
| `RAGOrchestratorService` | Main entry — 7-step pipeline orchestration |
| `EmbeddingService` | OpenAI embeddings + 30d Redis cache |
| `CohereRerankService` | Cohere rerank-multilingual-v3.0 (production reranker) |
| `RerankingService` | Local fallback (heuristic + OpenAI score) |
| `QueryClassifierService` | LLM-based STRUCTURED/SEMANTIC/COMPLEX classifier with rules fallback |
| `QueryRouterService` | Routes classified queries to the right retrieval strategy |
| `KnowledgeGraphService` | Neo4j via laudis/neo4j-php-client |
| `VectorMemoryService` | ChromaDB v2 API (direct Guzzle, no SDK lock-in) |
| `WorkingMemoryService` | Redis LRU 7±2 items per bot (conversation context) |
| `QueryCacheService` | L1 exact / L2 normalized / L3 semantic similarity |
| `CacheStrategyService` | Adaptive TTL + cache decisions per query type |
| `PreComputedResponseService` | Pattern-matched FAQ responses (zero-LLM) |

---

## Roadmap

**v0.2 (Q3 2026)** — Driver abstraction
- Vector store interface (ChromaDB / Qdrant / Pinecone / Weaviate)
- Graph DB interface (Neo4j / Memgraph / NebulaGraph)
- Reranker interface (Cohere / Voyage / BGE local / custom)

**v0.3** — Examples + tests
- Working example app (`examples/chatbot/`)
- Migrated test suite (currently only CohereRerank shipped)

**v1.0** — Production hardening
- Telemetry hooks (Langfuse, OTLP)
- Performance benchmarks
- Cost guards integration

---

## Contributing

This is alpha — the API will move. The fastest way to influence it is to open an issue with your use case before I lock decisions.

PRs especially welcome for:
- Pinecone / Qdrant driver
- Memgraph driver
- Voyage / BGE reranker
- Migrated tests for Memory/Cache services

---

## License

MIT — see [`LICENSE`](LICENSE).

---

## About the author

**Greg Robinson** — AI Architect, RAG & agentic systems, [Audelalia](https://audelalia.fr) (🇫🇷 Montpellier).

Companion repos:
- [laravel-mcp-server](https://github.com/grobinson3108/laravel-mcp-server) — Expose your Laravel app to Claude via MCP
- [mcp-server-saas-gateway](https://github.com/grobinson3108/mcp-server-saas-gateway) — One MCP server, N REST APIs (TypeScript)
- [vibe-coding-arsenal](https://github.com/grobinson3108/vibe-coding-arsenal) — 38 Claude Code commands and skills
- [claude-code-agents-laravel-vue](https://github.com/grobinson3108/claude-code-agents-laravel-vue) — 9 stack-specific sub-agents

If this saves you days of plumbing, ⭐ goes a long way.
