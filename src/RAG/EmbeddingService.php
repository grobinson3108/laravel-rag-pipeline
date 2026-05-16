<?php

namespace Grobinson3108\LaravelRagPipeline\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EmbeddingService
 *
 * Génère des embeddings vectoriels via OpenAI text-embedding-3-large.
 * Utilise un cache Redis pour éviter la régénération d'embeddings identiques.
 *
 * Performance target: <500ms par document
 * Cache hit: <10ms
 */
class EmbeddingService
{
    private const EMBEDDING_MODEL = 'text-embedding-3-large';
    private const EMBEDDING_DIMENSIONS = 1536;
    private const CACHE_TTL = 86400 * 30; // 30 jours
    private const BATCH_SIZE = 100; // OpenAI limite

    private string $apiKey;
    private string $apiUrl = 'https://api.openai.com/v1/embeddings';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key') ?? '';

        // Only throw in production environments
        if (empty($this->apiKey) && !in_array(app()->environment(), ['testing', 'local'])) {
            throw new \RuntimeException('OpenAI API key not configured');
        }
    }

    /**
     * Génère un embedding pour un texte donné
     *
     * @param string $text Le texte à embedder
     * @param bool $useCache Utiliser le cache ou forcer la régénération
     * @return array Vector embedding (1536 dimensions)
     * @throws \Exception Si l'API échoue
     */
    public function generateEmbedding(string $text, bool $useCache = true): array
    {
        // Normaliser le texte
        $text = $this->normalizeText($text);

        // Vérifier le cache
        if ($useCache) {
            $cached = $this->getCachedEmbedding($text);
            if ($cached !== null) {
                Log::debug('Embedding cache hit', ['text_length' => strlen($text)]);
                return $cached;
            }
        }

        // Générer via API
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($this->apiUrl, [
                'model' => self::EMBEDDING_MODEL,
                'input' => $text,
                'dimensions' => self::EMBEDDING_DIMENSIONS,
            ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI API error: ' . $response->body());
            }

            $data = $response->json();
            $embedding = $data['data'][0]['embedding'] ?? null;

            if (!$embedding || count($embedding) !== self::EMBEDDING_DIMENSIONS) {
                throw new \Exception('Invalid embedding response from OpenAI');
            }

            $duration = (microtime(true) - $startTime) * 1000;
            Log::info('Embedding generated', [
                'text_length' => strlen($text),
                'duration_ms' => round($duration, 2),
            ]);

            // Mettre en cache
            $this->cacheEmbedding($text, $embedding);

            return $embedding;

        } catch (\Exception $e) {
            Log::error('Embedding generation failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);
            throw $e;
        }
    }

    /**
     * Génère des embeddings pour plusieurs textes en batch
     * Plus efficace que des appels individuels
     *
     * @param array $texts Liste de textes à embedder
     * @param bool $useCache Utiliser le cache
     * @return array Tableau associatif [text => embedding]
     */
    public function batchEmbeddings(array $texts, bool $useCache = true): array
    {
        if (empty($texts)) {
            return [];
        }

        $results = [];
        $toGenerate = [];

        // Séparer ce qui est en cache de ce qui doit être généré
        foreach ($texts as $index => $text) {
            $normalizedText = $this->normalizeText($text);

            if ($useCache) {
                $cached = $this->getCachedEmbedding($normalizedText);
                if ($cached !== null) {
                    $results[$index] = $cached;
                    continue;
                }
            }

            $toGenerate[$index] = $normalizedText;
        }

        // Générer les embeddings manquants par batch
        if (!empty($toGenerate)) {
            $batches = array_chunk($toGenerate, self::BATCH_SIZE, true);

            foreach ($batches as $batch) {
                $batchResults = $this->generateBatch(array_values($batch));

                foreach ($batch as $index => $text) {
                    $embedding = array_shift($batchResults);
                    $results[$index] = $embedding;
                    $this->cacheEmbedding($text, $embedding);
                }
            }
        }

        // Retourner dans l'ordre original
        ksort($results);
        return $results;
    }

    /**
     * Récupère un embedding depuis le cache
     *
     * @param string $text Le texte normalisé
     * @return array|null L'embedding ou null si pas en cache
     */
    public function getCachedEmbedding(string $text): ?array
    {
        $cacheKey = $this->getCacheKey($text);
        $cached = Cache::get($cacheKey);

        return $cached ? json_decode($cached, true) : null;
    }

    /**
     * Met un embedding en cache
     *
     * @param string $text Le texte normalisé
     * @param array $embedding L'embedding à cacher
     */
    private function cacheEmbedding(string $text, array $embedding): void
    {
        $cacheKey = $this->getCacheKey($text);
        Cache::put($cacheKey, json_encode($embedding), self::CACHE_TTL);
    }

    /**
     * Génère une clé de cache unique pour un texte
     *
     * @param string $text Le texte normalisé
     * @return string La clé de cache
     */
    private function getCacheKey(string $text): string
    {
        return 'embedding:' . hash('sha256', $text);
    }

    /**
     * Normalise un texte pour l'embedding
     * - Supprime espaces multiples
     * - Trim
     * - Lowercase (optionnel selon use case)
     *
     * @param string $text Le texte brut
     * @return string Le texte normalisé
     */
    private function normalizeText(string $text): string
    {
        // Supprimer les espaces multiples
        $text = preg_replace('/\s+/', ' ', $text);

        // Trim
        $text = trim($text);

        return $text;
    }

    /**
     * Génère un batch d'embeddings via l'API OpenAI
     *
     * @param array $texts Liste de textes normalisés
     * @return array Liste d'embeddings dans le même ordre
     * @throws \Exception Si l'API échoue
     */
    private function generateBatch(array $texts): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->apiUrl, [
                'model' => self::EMBEDDING_MODEL,
                'input' => $texts,
                'dimensions' => self::EMBEDDING_DIMENSIONS,
            ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI API batch error: ' . $response->body());
            }

            $data = $response->json();
            $embeddings = [];

            foreach ($data['data'] as $item) {
                $embeddings[] = $item['embedding'];
            }

            $duration = (microtime(true) - $startTime) * 1000;
            Log::info('Batch embeddings generated', [
                'count' => count($texts),
                'duration_ms' => round($duration, 2),
                'avg_ms' => round($duration / count($texts), 2),
            ]);

            return $embeddings;

        } catch (\Exception $e) {
            Log::error('Batch embedding generation failed', [
                'error' => $e->getMessage(),
                'count' => count($texts),
            ]);
            throw $e;
        }
    }

    /**
     * Invalide le cache pour un texte spécifique
     *
     * @param string $text Le texte dont il faut invalider le cache
     */
    public function invalidateCache(string $text): void
    {
        $normalizedText = $this->normalizeText($text);
        $cacheKey = $this->getCacheKey($normalizedText);
        Cache::forget($cacheKey);
    }

    /**
     * Calcule la similarité cosine entre deux embeddings
     * Utile pour vérifier la qualité des embeddings
     *
     * @param array $embedding1 Premier embedding
     * @param array $embedding2 Deuxième embedding
     * @return float Score de similarité entre -1 et 1
     */
    public function cosineSimilarity(array $embedding1, array $embedding2): float
    {
        if (count($embedding1) !== count($embedding2)) {
            throw new \InvalidArgumentException('Embeddings must have same dimensions');
        }

        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $norm1 += $embedding1[$i] * $embedding1[$i];
            $norm2 += $embedding2[$i] * $embedding2[$i];
        }

        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);

        if ($norm1 == 0 || $norm2 == 0) {
            return 0;
        }

        return $dotProduct / ($norm1 * $norm2);
    }
}
