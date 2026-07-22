<?php
namespace Custom\AiProductRecommendation\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a "customer interest vector" from purchase + recently-viewed history,
 * then reuses OpenSearchClient::knnSearch() (same one used for product-page
 * recommendations) to find matching products.
 */
class CustomerRecommendationProvider
{
    private const PURCHASE_WEIGHT = 0.7;
    private const VIEWED_WEIGHT   = 0.3;

    private const MAX_PURCHASED_TO_CONSIDER = 10;
    private const MAX_VIEWED_TO_CONSIDER    = 10;

    // Same store -> language mapping used in RecommendationProvider
    private const STORE_LANGUAGE_MAP = [
        1 => 'en',
        2 => 'hi',
        3 => 'gu',
        4 => 'ml',
    ];

    private ResourceConnection $resourceConnection;
    private CustomerSession $customerSession;
    private OpenSearchClient $openSearchClient;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;


    public function debugGetPurchasedIds(): array
{
    $customerId = (int) $this->customerSession->getCustomerId();
    return $this->getRecentlyPurchasedProductIds($customerId);
}

public function debugGetViewedIds(): array
{
    $customerId = (int) $this->customerSession->getCustomerId();
    return $this->getRecentlyViewedProductIds($customerId);
}

    public function __construct(
        ResourceConnection $resourceConnection,
        CustomerSession $customerSession,
        OpenSearchClient $openSearchClient,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->customerSession    = $customerSession;
        $this->openSearchClient   = $openSearchClient;
        $this->storeManager       = $storeManager;
        $this->logger             = $logger;
    }

    /**
     * Guests get nothing — section is hidden entirely for them (per product decision)
     */
    public function isEligible(): bool
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * @return int[] Recommended product IDs, most relevant first
     */
    public function getRecommendedProductIds(int $count = 8): array
    {
        if (!$this->isEligible()) {
            return [];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $storeId    = (int) $this->storeManager->getStore()->getId();
        $language   = self::STORE_LANGUAGE_MAP[$storeId] ?? 'en';

        $purchasedIds = $this->getRecentlyPurchasedProductIds($customerId);
        $viewedIds    = $this->getRecentlyViewedProductIds($customerId);

        if (empty($purchasedIds) && empty($viewedIds)) {
            // Not enough history yet — caller should hide the section
            return [];
        }

        $interestVector = $this->buildInterestVector($purchasedIds, $viewedIds, $storeId, $language);

        if (empty($interestVector)) {
            return [];
        }

        // Ask for a few extra so we still have `$count` left after excluding owned items
        $results = $this->openSearchClient->knnSearch(
            embedding: $interestVector,
            storeId:   $storeId,
            language:  $language,
            k:         $count + count($purchasedIds)
        );

        $recommended = [];
        foreach ($results as $result) {
            $productId = $result['product_id'];

            if (in_array($productId, $purchasedIds, true)) {
                continue; // don't recommend things they already bought
            }

            $recommended[] = $productId;

            if (count($recommended) >= $count) {
                break;
            }
        }

        return $recommended;
    }

    /**
     * Fetches each product's stored vector and combines them into one
     * weighted-average vector representing the customer's overall interest.
     */
    private function buildInterestVector(array $purchasedIds, array $viewedIds, int $storeId, string $language): array
    {
        $weightedVectors = [];

        foreach ($purchasedIds as $productId) {
            $vector = $this->openSearchClient->getProductEmbedding($productId, $storeId, $language);
            if (!empty($vector)) {
                $weightedVectors[] = ['vector' => $vector, 'weight' => self::PURCHASE_WEIGHT];
            }
        }

        foreach ($viewedIds as $productId) {
            $vector = $this->openSearchClient->getProductEmbedding($productId, $storeId, $language);
            if (!empty($vector)) {
                $weightedVectors[] = ['vector' => $vector, 'weight' => self::VIEWED_WEIGHT];
            }
        }

        return $this->weightedAverage($weightedVectors);
    }

    private function weightedAverage(array $weightedVectors): array
    {
        if (empty($weightedVectors)) {
            return [];
        }

        $dimension   = count($weightedVectors[0]['vector']);
        $sum         = array_fill(0, $dimension, 0.0);
        $totalWeight = 0.0;

        foreach ($weightedVectors as $item) {
            $totalWeight += $item['weight'];
            foreach ($item['vector'] as $i => $value) {
                $sum[$i] += $value * $item['weight'];
            }
        }

        if ($totalWeight <= 0.0) {
            return [];
        }

        return array_map(static fn($v) => $v / $totalWeight, $sum);
    }

    /**
     * Most recent distinct products this customer has purchased, across all their orders.
     */
    private function getRecentlyPurchasedProductIds(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $itemTable  = $this->resourceConnection->getTableName('sales_order_item');

        $select = $connection->select()
            ->from(['oi' => $itemTable], ['product_id'])
            ->join(['o' => $orderTable], 'o.entity_id = oi.order_id', [])
            ->where('o.customer_id = ?', $customerId)
            ->order('o.created_at DESC')
            ->limit(self::MAX_PURCHASED_TO_CONSIDER);

        try {
            return array_map('intval', array_unique($connection->fetchCol($select)));
        } catch (\Exception $e) {
            $this->logger->error('CustomerRecommendationProvider: failed to fetch purchase history — ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Most recent distinct products this customer has viewed while logged in.
     * Requires Magento_Reports (already enabled) — populates automatically as
     * logged-in customers browse product pages, no extra config needed.
     */
    private function getRecentlyViewedProductIds(int $customerId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $viewTable  = $this->resourceConnection->getTableName('report_viewed_product_index');

        if (!$connection->isTableExists($viewTable)) {
            return [];
        }

        $select = $connection->select()
            ->from($viewTable, ['product_id'])
            ->where('customer_id = ?', $customerId)
            ->order('added_at DESC')
            ->limit(self::MAX_VIEWED_TO_CONSIDER);

        try {
            return array_map('intval', array_unique($connection->fetchCol($select)));
        } catch (\Exception $e) {
            $this->logger->error('CustomerRecommendationProvider: failed to fetch viewed products — ' . $e->getMessage());
            return [];
        }
    }
}