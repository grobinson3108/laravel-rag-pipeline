<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Embeddings (OpenAI text-embedding-3-large by default)
    |--------------------------------------------------------------------------
    */
    'embeddings' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('RAG_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'cache_ttl' => env('RAG_EMBEDDING_CACHE_TTL', 60 * 60 * 24 * 30), // 30 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Reranking
    |--------------------------------------------------------------------------
    | Two strategies are bundled:
    |  - cohere (production-grade, recommended for >100 docs)
    |  - local  (heuristic + OpenAI score, fallback when Cohere not configured)
    */
    'reranking' => [
        'default' => env('RAG_RERANKER', 'cohere'), // 'cohere' | 'local'
        'cohere' => [
            'model' => env('RAG_COHERE_MODEL', 'rerank-multilingual-v3.0'),
            'top_n' => env('RAG_COHERE_TOP_N', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query classifier
    |--------------------------------------------------------------------------
    | Classifies incoming queries as STRUCTURED, SEMANTIC, or COMPLEX
    | to route them to the right retrieval strategy.
    */
    'classifier' => [
        'use_llm' => env('RAG_CLASSIFIER_LLM', true),
        'llm_model' => env('RAG_CLASSIFIER_LLM_MODEL', 'gpt-4o-mini'),
        'cache_ttl' => env('RAG_CLASSIFIER_CACHE_TTL', 60 * 60 * 24), // 24h
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector store (ChromaDB by default)
    |--------------------------------------------------------------------------
    */
    'vector_store' => [
        'driver' => env('RAG_VECTOR_DRIVER', 'chromadb'),
        'collection_prefix' => env('RAG_VECTOR_PREFIX', 'rag'),
        'chromadb' => [
            'host' => env('CHROMA_HOST', 'http://localhost:8000'),
            'tenant' => env('CHROMA_TENANT', 'default_tenant'),
            'database' => env('CHROMA_DATABASE', 'default_database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge graph (Neo4j)
    |--------------------------------------------------------------------------
    */
    'graph' => [
        'driver' => env('RAG_GRAPH_DRIVER', 'neo4j'),
        'neo4j' => [
            'uri' => env('NEO4J_URI', 'bolt://localhost:7687'),
            'user' => env('NEO4J_USER', 'neo4j'),
            'password' => env('NEO4J_PASSWORD', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Working memory (Redis LRU 7±2 items)
    |--------------------------------------------------------------------------
    */
    'working_memory' => [
        'ttl' => env('RAG_WORKING_MEMORY_TTL', 60 * 60 * 24), // 24h
        'max_items' => env('RAG_WORKING_MEMORY_MAX', 9), // Miller's law: 7±2
    ],

    /*
    |--------------------------------------------------------------------------
    | Query cache (L1/L2/L3 with embedding similarity)
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'l1_ttl' => env('RAG_CACHE_L1_TTL', 60 * 5),       // 5 min exact match
        'l2_ttl' => env('RAG_CACHE_L2_TTL', 60 * 60),      // 1 h normalized
        'l3_ttl' => env('RAG_CACHE_L3_TTL', 60 * 60 * 6),  // 6 h semantic similarity
        'similarity_threshold' => env('RAG_CACHE_SIMILARITY', 0.92),
    ],
];
