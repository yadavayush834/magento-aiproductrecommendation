<?php
namespace Custom\AiProductRecommendation\Observer;

use Custom\AiProductRecommendation\Model\RecommendationProvider;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens for catalog_product_save_after event
 * Clears the recommendation cache for the saved product
 */
class ProductSaveObserver implements ObserverInterface
{
    private RecommendationProvider $recommendationProvider;
    private LoggerInterface $logger;

    public function __construct(
        RecommendationProvider $recommendationProvider,
        LoggerInterface $logger
    ) {
        $this->recommendationProvider = $recommendationProvider;
        $this->logger                 = $logger;
    }

    public function execute(Observer $observer): void
    {
        $product   = $observer->getEvent()->getProduct();
        $productId = (int) $product->getId();

        $this->recommendationProvider->invalidateProductCache($productId);
        $this->logger->info("AI Recommendation: Cache cleared for product {$productId}");
    }
}
