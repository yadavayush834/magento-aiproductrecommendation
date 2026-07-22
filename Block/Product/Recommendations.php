<?php
namespace Custom\AiProductRecommendation\Block\Product;

use Custom\AiProductRecommendation\Model\RecommendationProvider;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Block\Product\Context;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

/**
 * Block for the "You May Also Like" widget on product pages
 * Loaded in the product detail page layout
 */
class Recommendations extends AbstractProduct
{
    private RecommendationProvider $recommendationProvider;
    private ProductRepositoryInterface $productRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        RecommendationProvider $recommendationProvider,
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->recommendationProvider = $recommendationProvider;
        $this->productRepository      = $productRepository;
        $this->searchCriteriaBuilder  = $searchCriteriaBuilder;
        $this->logger                 = $logger;
    }

    /**
     * Get recommended products for the current product page
     */
    public function getRecommendedProducts(): array
    {
        try {
            $currentProduct = $this->getProduct();
        } catch (\Exception $e) {
            $this->logger->error('AI Recommendation: could not get current product — ' . $e->getMessage());
            return [];
        }

        if (!$currentProduct || !$currentProduct->getId()) {
            return [];
        }

        $productId = (int) $currentProduct->getId();

        // Get recommended product IDs
        $recommendedIds = $this->recommendationProvider->getRecommendationsForProduct($productId);

        if (empty($recommendedIds)) {
            return [];
        }

        // Load full product objects
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('entity_id', $recommendedIds, 'in')
            ->addFilter('status', 1) // enabled only
            ->create();

        try {
            $result   = $this->productRepository->getList($criteria);
            $products = $result->getItems();

            // Sort by the original recommendation order
            $ordered = [];
            foreach ($recommendedIds as $id) {
                foreach ($products as $product) {
                    if ((int) $product->getId() === $id) {
                        $ordered[] = $product;
                        break;
                    }
                }
            }

            return $ordered;

        } catch (\Exception $e) {
            $this->logger->error('AI Recommendation block error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if recommendations are enabled
     */
    public function isEnabled(): bool
    {
        return (bool) $this->_scopeConfig->getValue('ai_recommendation/general/enabled');
    }
}
