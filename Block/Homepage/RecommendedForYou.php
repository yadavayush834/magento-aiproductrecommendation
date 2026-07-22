<?php
namespace Custom\AiProductRecommendation\Block\Homepage;

use Custom\AiProductRecommendation\Model\CustomerRecommendationProvider;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class RecommendedForYou extends Template
{
    private CustomerRecommendationProvider $recommendationProvider;
    private ProductCollectionFactory $productCollectionFactory;
    private ImageHelper $imageHelper;
    private PriceHelper $priceHelper;
    private ?array $products = null;

    public function __construct(
        Context $context,
        CustomerRecommendationProvider $recommendationProvider,
        ProductCollectionFactory $productCollectionFactory,
        ImageHelper $imageHelper,
        PriceHelper $priceHelper,
        array $data = []
    ) {
        $this->recommendationProvider   = $recommendationProvider;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->imageHelper              = $imageHelper;
        $this->priceHelper              = $priceHelper;
        parent::__construct($context, $data);
    }

    public function shouldDisplay(): bool
    {
        return $this->recommendationProvider->isEligible() && !empty($this->getRecommendedProducts());
    }

    /**
     * @return Product[]
     */
    public function getRecommendedProducts(): array
    {
        if ($this->products !== null) {
            return $this->products;
        }

        if (!$this->recommendationProvider->isEligible()) {
            return $this->products = [];
        }

        $productIds = $this->recommendationProvider->getRecommendedProductIds(8);

        if (empty($productIds)) {
            return $this->products = [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'price', 'small_image'])
            ->addIdFilter($productIds)
            ->addAttributeToFilter('status', 1);

        $productsById = [];
        foreach ($collection as $product) {
            $productsById[$product->getId()] = $product;
        }

        $ordered = [];
        foreach ($productIds as $id) {
            if (isset($productsById[$id])) {
                $ordered[] = $productsById[$id];
            }
        }

        return $this->products = $ordered;
    }

    public function getImageUrl(Product $product): string
    {
        return $this->imageHelper->init($product, 'product_small_image')->getUrl();
    }

    public function getFormattedPrice(Product $product): string
    {
        return $this->priceHelper->currency($product->getFinalPrice(), true, false);
    }

    /**
     * Temporary debug helper — safe to remove once the feature is confirmed working
     */
    public function getRecommendationProviderDebug(): array
    {
        $eligible = $this->recommendationProvider->isEligible();
        $ids = $eligible ? $this->recommendationProvider->getRecommendedProductIds(8) : [];

        return [
            'eligible'       => $eligible,
            'purchased'      => $eligible ? $this->recommendationProvider->debugGetPurchasedIds() : [],
            'viewed'         => $eligible ? $this->recommendationProvider->debugGetViewedIds() : [],
            'vectorBuilt'    => !empty($ids),
            'recommendedIds' => $ids,
        ];
    }
}