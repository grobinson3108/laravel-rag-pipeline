<?php

namespace Grobinson3108\LaravelRagPipeline\RAG;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RerankingService
 *
 * Améliore la précision des résultats RAG en utilisant un cross-encoder
 * pour rescorer les documents retournés par la recherche vectorielle.
 *
 * Le cross-encoder analyse la pertinence query-document de manière plus fine
 * que la simple similarité cosine des embeddings.
 *
 * Performance target: <50ms pour 10 résultats
 */
class RerankingService
{
    private const RELEVANCE_THRESHOLD = 0.7; // Score minimum pour considérer pertinent
    private const MAX_RESULTS = 5; // Nombre de résultats à retourner après reranking

    private ?string $apiKey;
    private bool $useOpenAI;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key');
        $this->useOpenAI = !empty($this->apiKey);
    }

    /**
     * Rerank les résultats d'une recherche vectorielle
     *
     * @param string $query La query originale
     * @param array $results Résultats de VectorSearchService
     * @param int $topK Nombre de résultats à retourner
     * @return array Résultats rerankés et filtrés
     */
    public function rerank(string $query, array $results, int $topK = self::MAX_RESULTS): array
    {
        if (empty($results)) {
            return [];
        }

        $startTime = microtime(true);

        try {
            // Scorer chaque résultat
            $scored = [];
            foreach ($results as $result) {
                $content = $result['content'] ?? $result['document'] ?? '';
                if (empty($content)) {
                    continue;
                }

                $score = $this->scoreRelevance($query, $content);

                // Filtrer par seuil de pertinence
                if ($score >= self::RELEVANCE_THRESHOLD) {
                    $scored[] = array_merge($result, [
                        'rerank_score' => $score,
                        'original_score' => $result['distance'] ?? $result['score'] ?? 0,
                    ]);
                }
            }

            // Trier par score décroissant
            usort($scored, function ($a, $b) {
                return $b['rerank_score'] <=> $a['rerank_score'];
            });

            // Prendre les top K
            $reranked = array_slice($scored, 0, $topK);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('Reranking completed', [
                'query_length' => strlen($query),
                'input_count' => count($results),
                'output_count' => count($reranked),
                'filtered_count' => count($results) - count($scored),
                'duration_ms' => round($duration, 2),
            ]);

            // Performance warning si >50ms
            if ($duration > 50) {
                Log::warning('Reranking exceeded target', [
                    'duration_ms' => round($duration, 2),
                    'target_ms' => 50,
                    'input_count' => count($results),
                ]);
            }

            return $reranked;

        } catch (\Exception $e) {
            Log::error('Reranking failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100),
                'results_count' => count($results),
            ]);

            // Fallback: retourner résultats originaux
            return array_slice($results, 0, $topK);
        }
    }

    /**
     * Score la pertinence d'un document pour une query
     *
     * Méthodes disponibles:
     * 1. OpenAI GPT-4-mini (si API key disponible) - Plus précis mais plus lent
     * 2. Heuristique simple (fallback) - Rapide mais moins précis
     *
     * @param string $query La query
     * @param string $document Le document à scorer
     * @return float Score entre 0 et 1
     */
    public function scoreRelevance(string $query, string $document): float
    {
        // Méthode 1: Utiliser OpenAI si disponible
        if ($this->useOpenAI) {
            return $this->scoreWithOpenAI($query, $document);
        }

        // Méthode 2: Heuristique simple (fallback)
        return $this->scoreWithHeuristic($query, $document);
    }

    /**
     * Score avec OpenAI GPT-4-mini
     * Demande au LLM de scorer la pertinence sur 10
     *
     * @param string $query La query
     * @param string $document Le document
     * @return float Score normalisé entre 0 et 1
     */
    private function scoreWithOpenAI(string $query, string $document): float
    {
        try {
            // Tronquer le document si trop long (max 1000 chars)
            $truncatedDoc = substr($document, 0, 1000);

            $prompt = "Rate the relevance of this document to the query on a scale of 0-10. "
                    . "Only respond with a number.\n\n"
                    . "Query: {$query}\n\n"
                    . "Document: {$truncatedDoc}\n\n"
                    . "Relevance score (0-10):";

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a relevance scoring assistant. Respond only with a number between 0 and 10.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0,
                'max_tokens' => 5,
            ]);

            if (!$response->successful()) {
                throw new \Exception('OpenAI API error: ' . $response->status());
            }

            $data = $response->json();
            $scoreText = trim($data['choices'][0]['message']['content'] ?? '0');

            // Extraire le nombre
            preg_match('/(\d+(?:\.\d+)?)/', $scoreText, $matches);
            $score = isset($matches[1]) ? (float) $matches[1] : 0;

            // Normaliser sur 0-1
            return min(max($score / 10, 0), 1);

        } catch (\Exception $e) {
            Log::warning('OpenAI reranking failed, using heuristic', [
                'error' => $e->getMessage(),
            ]);

            // Fallback sur heuristique
            return $this->scoreWithHeuristic($query, $document);
        }
    }

    /**
     * Score avec heuristique simple
     * Basé sur:
     * - Présence des mots de la query dans le document
     * - Densité des mots clés
     * - Position des mots clés (début = mieux)
     *
     * @param string $query La query
     * @param string $document Le document
     * @return float Score entre 0 et 1
     */
    private function scoreWithHeuristic(string $query, string $document): float
    {
        // Normaliser
        $queryLower = strtolower($query);
        $docLower = strtolower($document);

        // Extraire les mots de la query (minimum 3 chars)
        $queryWords = array_filter(
            preg_split('/\s+/', $queryLower),
            fn($word) => strlen($word) >= 3
        );

        if (empty($queryWords)) {
            return 0.5; // Score neutre si query trop courte
        }

        $score = 0;
        $maxScore = 0;

        foreach ($queryWords as $word) {
            $maxScore += 1;

            // Mot exact présent?
            if (str_contains($docLower, $word)) {
                $score += 0.6;

                // Bonus si présent au début du document
                if (strpos($docLower, $word) < 100) {
                    $score += 0.2;
                }

                // Bonus si présent plusieurs fois
                $occurrences = substr_count($docLower, $word);
                if ($occurrences > 1) {
                    $score += min($occurrences * 0.1, 0.2);
                }
            }
        }

        // Normaliser
        return $maxScore > 0 ? min($score / $maxScore, 1) : 0;
    }

    /**
     * Filtre les résultats par seuil de pertinence
     *
     * @param array $results Résultats rerankés
     * @param float $threshold Seuil minimum (0-1)
     * @return array Résultats filtrés
     */
    public function filterByThreshold(array $results, float $threshold = self::RELEVANCE_THRESHOLD): array
    {
        return array_filter($results, function ($result) use ($threshold) {
            return ($result['rerank_score'] ?? 0) >= $threshold;
        });
    }

    /**
     * Obtient des explications pour le scoring
     * Utile pour debugging
     *
     * @param string $query La query
     * @param string $document Le document
     * @return array Explication du score
     */
    public function explainScore(string $query, string $document): array
    {
        $score = $this->scoreRelevance($query, $document);

        $queryLower = strtolower($query);
        $docLower = strtolower($document);

        $queryWords = array_filter(
            preg_split('/\s+/', $queryLower),
            fn($word) => strlen($word) >= 3
        );

        $matchedWords = [];
        $missedWords = [];

        foreach ($queryWords as $word) {
            if (str_contains($docLower, $word)) {
                $matchedWords[] = $word;
            } else {
                $missedWords[] = $word;
            }
        }

        return [
            'score' => $score,
            'threshold' => self::RELEVANCE_THRESHOLD,
            'is_relevant' => $score >= self::RELEVANCE_THRESHOLD,
            'query_words' => count($queryWords),
            'matched_words' => $matchedWords,
            'missed_words' => $missedWords,
            'match_ratio' => count($queryWords) > 0
                ? count($matchedWords) / count($queryWords)
                : 0,
            'method' => $this->useOpenAI ? 'openai' : 'heuristic',
        ];
    }
}
