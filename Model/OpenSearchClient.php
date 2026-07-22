<?php
namespace Custom\AiProductRecommendation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles all interactions with OpenSearch:
 * - Creating the k-NN index
 * - Indexing product embeddings
 * - Running k-NN similarity search
 */
class OpenSearchClient
{
    private ScopeConfigInterface $config;
    private LoggerInterface $logger;
    private ?string $baseUrl = null;
    private ?string $indexName = null;

    public function __construct(
        ScopeConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config  = $config;
        $this->logger  = $logger;
    }

    /**
     * Lazy-load the OpenSearch base URL from config (avoids constructor crash when config cache is cold)
     */
    private function getBaseUrl(): string
    {
        if ($this->baseUrl === null) {
            $host          = $this->config->getValue('ai_recommendation/opensearch/host') ?? 'localhost';
            $port          = $this->config->getValue('ai_recommendation/opensearch/port') ?? '9200';
            $this->baseUrl = "http://{$host}:{$port}";
        }
        return $this->baseUrl;
    }

    /**
     * Lazy-load the index name from config
     */
    private function getIndexName(): string
    {
        if ($this->indexName === null) {
            $this->indexName = $this->config->getValue('ai_recommendation/opensearch/index_name') ?? 'magento_products';
        }
        return $this->indexName;
    }

    // ─── Index Management ─────────────────────────────────────────────────────

    /**
     * Create the k-NN index in OpenSearch (run once during setup)
     */
    public function createIndex(): bool
    {
        $dimension = (int) ($this->config->getValue('ai_recommendation/opensearch/vector_dimension') ?? 768);

        $mapping = [
            'settings' => [
                'index' => [
                    'knn'                => true,
                    'knn.algo_param.ef_search' => 100,
                    'number_of_shards'   => 1,
                    'number_of_replicas' => 1,
                ]
            ],
            'mappings' => [
                'properties' => [
                    'product_id'   => ['type' => 'integer'],
                    'store_id'     => ['type' => 'integer'],
                    'language'     => ['type' => 'keyword'],
                    'sku'          => ['type' => 'keyword'],
                    'name'         => ['type' => 'text'],
                    'updated_at'   => ['type' => 'date'],
                    'embedding'    => [
                        'type'      => 'knn_vector',
                        'dimension' => $dimension,
                        'method'    => [
                            'name'       => 'hnsw',
                            'space_type' => 'cosinesimil',
                            'engine'     => 'lucene',
                            'parameters' => [
                                'ef_construction' => 128,
                                'm'               => 24,
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->request('PUT', "/{$this->getIndexName()}", $mapping);

        if ($response === null) {
            $this->logger->error('Failed to create OpenSearch index');
            return false;
        }

        $this->logger->info("OpenSearch index '{$this->getIndexName()}' created successfully");
        return true;
    }

    /**
     * Check if the index already exists
     */
    public function indexExists(): bool
    {
        $ch = curl_init("{$this->getBaseUrl()}/{$this->getIndexName()}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    // ─── Indexing ─────────────────────────────────────────────────────────────

    /**
     * Index or update a single product embedding
     */
    public function indexProduct(
        int $productId,
        int $storeId,
        string $language,
        string $sku,
        string $name,
        array $embedding
    ): bool {
        if (empty($embedding)) {
            $this->logger->warning("Empty embedding — skipping product {$productId}");
            return false;
        }

        $docId = "{$productId}_{$storeId}_{$language}";
        $body  = [
            'product_id' => $productId,
            'store_id'   => $storeId,
            'language'   => $language,
            'sku'        => $sku,
            'name'       => $name,
            'updated_at' => date('Y-m-d\TH:i:s'),
            'embedding'  => $embedding,
        ];

        $response = $this->request('PUT', "/{$this->getIndexName()}/_doc/{$docId}", $body);

        if ($response === null) {
            $this->logger->error("Failed to index product {$productId} [{$language}]");
            return false;
        }

        return true;
    }

    /**
     * Bulk index multiple products at once (faster for initial setup)
     */
    public function bulkIndex(array $documents): bool
    {
        if (empty($documents)) {
            return true;
        }

        $bulkBody = '';
        foreach ($documents as $doc) {
            $docId     = "{$doc['product_id']}_{$doc['store_id']}_{$doc['language']}";
            $bulkBody .= json_encode(['index' => ['_index' => $this->getIndexName(), '_id' => $docId]]) . "\n";
            $bulkBody .= json_encode($doc) . "\n";
        }

        $ch = curl_init("{$this->getBaseUrl()}/_bulk");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bulkBody,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-ndjson'],
            CURLOPT_TIMEOUT        => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error('Bulk index failed', ['http_code' => $httpCode]);
            return false;
        }

        $data = json_decode($response, true);
        if (!empty($data['errors'])) {
            $this->logger->warning('Some bulk index items failed', ['response' => $response]);
        }

        return true;
    }

    // ─── Search ───────────────────────────────────────────────────────────────

    /**
     * Find the k nearest products to the given embedding vector
     *
     * @param float[] $embedding   The query vector
     * @param int     $storeId     Filter by store view
     * @param string  $language    Filter by language (en, hi, gu, ml)
     * @param int     $k           Number of results to return
     * @param int     $excludeId   Product ID to exclude (e.g. current product)
     * @return array               Array of matching product_ids with scores
     */
    public function knnSearch(
        array $embedding,
        int $storeId,
        string $language,
        int $k = 6,
        int $excludeId = 0
    ): array {
        if (empty($embedding)) {
            return [];
        }

        $query = [
            'size'  => $k + 1, // +1 in case we need to exclude current product
            'query' => [
                'bool' => [
                    'must'   => [
                        ['knn' => [
                            'embedding' => [
                                'vector' => $embedding,
                                'k'      => $k + 1,
                            ]
                        ]]
                    ],
                    'filter' => [
                        ['term' => ['store_id' => $storeId]],
                        ['term' => ['language'  => $language]],
                    ]
                ]
            ],
            '_source' => ['product_id', 'sku', 'name'],
        ];

        $response = $this->request('POST', "/{$this->getIndexName()}/_search", $query);

        if ($response === null || !isset($response['hits']['hits'])) {
            return [];
        }

        $results = [];
        foreach ($response['hits']['hits'] as $hit) {
            $productId = (int) $hit['_source']['product_id'];

            // Exclude the current product from recommendations
            if ($excludeId > 0 && $productId === $excludeId) {
                continue;
            }

            $results[] = [
                'product_id' => $productId,
                'sku'        => $hit['_source']['sku'],
                'score'      => $hit['_score'],
            ];

            if (count($results) >= $k) {
                break;
            }
        }

        return $results;
    }

    /**
     * Get a stored embedding for a specific product (used for product page recommendations)
     */
    public function getProductEmbedding(int $productId, int $storeId, string $language): array
    {
        $docId    = "{$productId}_{$storeId}_{$language}";
        $response = $this->request('GET', "/{$this->getIndexName()}/_doc/{$docId}");

        if ($response === null || !isset($response['_source']['embedding'])) {
            return [];
        }

        return $response['_source']['embedding'];
    }

    // ─── HTTP Helper ──────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = []): ?array
    {
        $url = $this->getBaseUrl() . $path;
        $ch  = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ];

        if (!empty($body)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("OpenSearch cURL error: {$error}", ['path' => $path]);
            return null;
        }

        if ($httpCode >= 400) {
            $this->logger->error("OpenSearch HTTP {$httpCode}", ['path' => $path, 'response' => $response]);
            return null;
        }

        return json_decode($response, true);
    }
}
