<?php
namespace Custom\AiProductRecommendation\Cron;

use Custom\AiProductRecommendation\Model\EmbeddingServiceInterface;
use Custom\AiProductRecommendation\Model\OpenSearchClient;
use Custom\AiProductRecommendation\Model\RecommendationProvider;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs every night at 2 AM (see crontab.xml)
 * Finds products modified in the last 25 hours and updates their embeddings
 */
class UpdateEmbeddings
{
    // Languages to generate embeddings for
    private const LANGUAGES = ['en', 'hi', 'gu', 'ml'];

    // Store ID to language mapping
    private const STORE_MAP = [
        'en' => 1,
        'hi' => 2,
        'gu' => 3,
        'ml' => 4,
    ];

    private CollectionFactory $productCollectionFactory;
    private EmbeddingServiceInterface $embeddingService;
    private OpenSearchClient $openSearch;
    private RecommendationProvider $recommendationProvider;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    public function __construct(
        CollectionFactory $productCollectionFactory,
        EmbeddingServiceInterface $embeddingService,
        OpenSearchClient $openSearch,
        RecommendationProvider $recommendationProvider,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->embeddingService         = $embeddingService;
        $this->openSearch               = $openSearch;
        $this->recommendationProvider   = $recommendationProvider;
        $this->storeManager             = $storeManager;
        $this->logger                   = $logger;
    }

    /**
     * Main cron entry point
     */
    public function execute(): void
    {
        $startTime = time();
        $this->logger->info('AI Recommendation: Embedding update started');

        // Find products modified in the last 25 hours
        $since = date('Y-m-d H:i:s', strtotime('-25 hours'));

        $collection = $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'description', 'short_description', 'status'])
            ->addAttributeToFilter('updated_at', ['gteq' => $since])
            ->addAttributeToFilter('status', 1); // enabled only

        $total     = $collection->getSize();
        $processed = 0;
        $failed    = 0;

        $this->logger->info("AI Recommendation: Found {$total} modified products");

        foreach ($collection as $product) {
            $productId = (int) $product->getId();

            // Build text for embedding
            $text = RecommendationProvider::buildProductText(
                name:             $product->getName() ?? '',
                description:      strip_tags($product->getDescription() ?? ''),
                shortDescription: strip_tags($product->getShortDescription() ?? '')
            );

            if (empty(trim($text))) {
                $this->logger->warning("Skipping product {$productId} — no text content");
                continue;
            }

            // Generate and index embedding for each language
            foreach (self::LANGUAGES as $language) {
                $storeId = self::STORE_MAP[$language] ?? 1;

                // Get text in current store language
                // In production: load product in store context for translated text
                $embedding = $this->embeddingService->getEmbedding($text);

                if (empty($embedding)) {
                    $this->logger->warning("Embedding failed for product {$productId} [{$language}]");
                    $failed++;
                    continue;
                }

                $success = $this->openSearch->indexProduct(
                    productId: $productId,
                    storeId:   $storeId,
                    language:  $language,
                    sku:       $product->getSku(),
                    name:      $product->getName(),
                    embedding: $embedding
                );

                if (!$success) {
                    $failed++;
                }
            }

            // Invalidate cache for this product
            $this->recommendationProvider->invalidateProductCache($productId);
            $processed++;
        }

        $duration = time() - $startTime;
        $this->logger->info("AI Recommendation: Update complete", [
            'total'     => $total,
            'processed' => $processed,
            'failed'    => $failed,
            'duration'  => "{$duration}s",
        ]);
    }
}
