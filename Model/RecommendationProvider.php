<?php
namespace Custom\AiProductRecommendation\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Core recommendation logic:
 * - Gets recommendations for a product page (uses stored vectors)
 * - Gets recommendations for a search query (generates vector on the fly)
 * - Handles Redis caching
 * - Maps store_id to language
 */
class RecommendationProvider
{
    // Cache key prefix
    private const CACHE_PREFIX = 'ai_reco_';
    private const CACHE_TAG    = 'ai_recommendation';

    // Store ID to language mapping for IndiaHandmade
    private const STORE_LANGUAGE_MAP = [
        1 => 'en',
        2 => 'hi',
        3 => 'gu',
        4 => 'ml',
    ];

    private OpenSearchClient $openSearch;
    private EmbeddingServiceInterface $embeddingService;
    private CacheInterface $cache;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private int $cacheTtl;

    public function __construct(
        OpenSearchClient $openSearch,
        EmbeddingServiceInterface $embeddingService,
        CacheInterface $cache,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        int $cacheTtl = 21600 // 6 hours
    ) {
        $this->openSearch       = $openSearch;
        $this->embeddingService = $embeddingService;
        $this->cache            = $cache;
        $this->storeManager     = $storeManager;
        $this->logger           = $logger;
        $this->cacheTtl         = $cacheTtl;
    }

    // ─── Product Page Recommendations ─────────────────────────────────────────

    /**
     * Get recommended product IDs for a given product page
     * Uses the product's pre-stored vector — no Bedrock/Ollama call needed
     *
     * @param int $productId  The product being viewed
     * @param int $count      How many recommendations to return
     * @return int[]          Array of recommended product IDs
     */
    public function getRecommendationsForProduct(int $productId, int $count = 6): array
    {
        $storeId  = (int) $this->storeManager->getStore()->getId();
        $language = $this->getLanguage($storeId);
        $cacheKey = self::CACHE_PREFIX . "product_{$productId}_{$storeId}_{$language}";

        // 1. Check cache first
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        // 2. Get the product's stored vector from OpenSearch
        $embedding = $this->openSearch->getProductEmbedding($productId, $storeId, $language);

        if (empty($embedding)) {
            $this->logger->warning("No embedding found for product {$productId} [{$language}]");
            return [];
        }

        // 3. k-NN search to find similar products
        $results = $this->openSearch->knnSearch(
            embedding:  $embedding,
            storeId:    $storeId,
            language:   $language,
            k:          $count,
            excludeId:  $productId
        );

        $productIds = array_column($results, 'product_id');

        // 4. Store in cache
        $this->cache->save(
            json_encode($productIds),
            $cacheKey,
            [self::CACHE_TAG],
            $this->cacheTtl
        );

        return $productIds;
    }

    // ─── Search Recommendations ────────────────────────────────────────────────

    /**
     * Get recommended product IDs for a search query
     * Generates a vector from the query text on the fly
     *
     * @param string $query   The user's search query
     * @param int    $count   How many results to return
     * @return int[]          Array of matching product IDs
     */
    public function getRecommendationsForSearch(string $query, int $count = 12): array
    {
        $storeId  = (int) $this->storeManager->getStore()->getId();
        $language = $this->getLanguage($storeId);
        $cacheKey = self::CACHE_PREFIX . "search_" . md5($query) . "_{$storeId}_{$language}";

        // 1. Check cache
        $cached = $this->cache->load($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        // 2. Generate vector for the search query
        $embedding = $this->embeddingService->getEmbedding($query);

        if (empty($embedding)) {
            $this->logger->warning("Failed to generate embedding for search: {$query}");
            return [];
        }

        // 3. k-NN search
        $results = $this->openSearch->knnSearch(
            embedding: $embedding,
            storeId:   $storeId,
            language:  $language,
            k:         $count
        );

        $productIds = array_column($results, 'product_id');

        // 4. Cache for 1 hour (shorter TTL for search — queries are more dynamic)
        $this->cache->save(
            json_encode($productIds),
            $cacheKey,
            [self::CACHE_TAG],
            3600
        );

        return $productIds;
    }

    // ─── Cache Invalidation ────────────────────────────────────────────────────

    /**
     * Invalidate cache for a specific product (called when product is updated)
     */
    public function invalidateProductCache(int $productId): void
    {
        foreach (self::STORE_LANGUAGE_MAP as $storeId => $language) {
            $cacheKey = self::CACHE_PREFIX . "product_{$productId}_{$storeId}_{$language}";
            $this->cache->remove($cacheKey);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Get the language code for the current store view
     */
    private function getLanguage(int $storeId): string
    {
        return self::STORE_LANGUAGE_MAP[$storeId] ?? 'en';
    }

    /**
     * Build the product text used for generating embeddings
     * Combines name + description + categories for better semantic matching
     */
    public static function buildProductText(
        string $name,
        string $description = '',
        string $shortDescription = '',
        string $categories = ''
    ): string {
        $parts = array_filter([$name, $shortDescription, $description, $categories]);
        $text  = implode('. ', $parts);

        // Limit to ~500 tokens to keep embedding costs low
        return mb_substr($text, 0, 2000);
    }
}
