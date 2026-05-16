<?php

declare(strict_types=1);

namespace Grobinson3108\LaravelRagPipeline;

use Grobinson3108\LaravelRagPipeline\Cache\CacheStrategyService;
use Grobinson3108\LaravelRagPipeline\Cache\PreComputedResponseService;
use Grobinson3108\LaravelRagPipeline\Cache\QueryCacheService;
use Grobinson3108\LaravelRagPipeline\Memory\KnowledgeGraphService;
use Grobinson3108\LaravelRagPipeline\Memory\VectorMemoryService;
use Grobinson3108\LaravelRagPipeline\Memory\WorkingMemoryService;
use Grobinson3108\LaravelRagPipeline\RAG\CohereRerankService;
use Grobinson3108\LaravelRagPipeline\RAG\EmbeddingService;
use Grobinson3108\LaravelRagPipeline\RAG\RAGOrchestratorService;
use Grobinson3108\LaravelRagPipeline\RAG\RerankingService;
use Grobinson3108\LaravelRagPipeline\Router\QueryClassifierService;
use Grobinson3108\LaravelRagPipeline\Router\QueryRouterService;
use Illuminate\Support\ServiceProvider;

class RagPipelineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rag-pipeline.php', 'rag-pipeline');

        // Singletons for stateless services (allow swapping via container binding)
        $this->app->singleton(EmbeddingService::class);
        $this->app->singleton(CohereRerankService::class);
        $this->app->singleton(RerankingService::class);
        $this->app->singleton(QueryClassifierService::class);
        $this->app->singleton(QueryRouterService::class);
        $this->app->singleton(KnowledgeGraphService::class);
        $this->app->singleton(VectorMemoryService::class);
        $this->app->singleton(WorkingMemoryService::class);
        $this->app->singleton(QueryCacheService::class);
        $this->app->singleton(CacheStrategyService::class);
        $this->app->singleton(PreComputedResponseService::class);
        $this->app->singleton(RAGOrchestratorService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/rag-pipeline.php' => config_path('rag-pipeline.php'),
        ], 'rag-pipeline-config');
    }
}
