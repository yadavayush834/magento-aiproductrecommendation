<?php
namespace Custom\AiProductRecommendation\Console\Command;

use Custom\AiProductRecommendation\Model\EmbeddingServiceInterface;
use Custom\AiProductRecommendation\Model\OpenSearchClient;
use Custom\AiProductRecommendation\Model\RecommendationProvider;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexProductsCommand extends Command
{
    private const STORE_LANGUAGE_MAP = [
        1 => 'en',
        2 => 'hi',
        3 => 'gu',
        4 => 'ml',
    ];

    private const BATCH_SIZE = 50;

    private ProductCollectionFactory $productCollectionFactory;
    private EmbeddingServiceInterface $embeddingService;
    private OpenSearchClient $openSearchClient;
    private StoreManagerInterface $storeManager;

    public function __construct(
        ProductCollectionFactory $productCollectionFactory,
        EmbeddingServiceInterface $embeddingService,
        OpenSearchClient $openSearchClient,
        StoreManagerInterface $storeManager
    ) {
        $this->productCollectionFactory = $productCollectionFactory;
        $this->embeddingService = $embeddingService;
        $this->openSearchClient = $openSearchClient;
        $this->storeManager = $storeManager;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('ai:recommendation:index')
            ->setDescription('Generate embeddings for all products and index them into OpenSearch')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Only index a specific store ID')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of products (for testing)');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->openSearchClient->indexExists()) {
            $output->writeln('<error>OpenSearch index does not exist yet.</error>');
            $output->writeln('Run "bin/magento ai:recommendation:setup" first.');
            return Command::FAILURE;
        }

        $storeOption = $input->getOption('store');
        $limitOption = $input->getOption('limit');

        $storeLanguageMap = self::STORE_LANGUAGE_MAP;
        if ($storeOption !== null) {
            $storeId = (int) $storeOption;
            if (!isset($storeLanguageMap[$storeId])) {
                $output->writeln("<error>Store ID {$storeId} is not in the configured language map.</error>");
                return Command::FAILURE;
            }
            $storeLanguageMap = [$storeId => $storeLanguageMap[$storeId]];
        }

        foreach ($storeLanguageMap as $storeId => $language) {
            $output->writeln("");
            $output->writeln("<info>Indexing store {$storeId} ({$language})...</info>");
            $this->indexStore((int) $storeId, $language, $limitOption !== null ? (int) $limitOption : null, $output);
        }

        $output->writeln('');
        $output->writeln('<info>Indexing complete.</info>');

        return Command::SUCCESS;
    }

    private function indexStore(int $storeId, string $language, ?int $limit, OutputInterface $output): void
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', 'description', 'short_description', 'sku'])
            ->addAttributeToFilter('status', 1)
            ->setStoreId($storeId);

        if ($limit !== null) {
            $collection->setPageSize($limit);
        }

        $total = $collection->getSize();
        $output->writeln("Found {$total} enabled products for store {$storeId}.");

        $batch = [];
        $processed = 0;
        $failed = 0;

        foreach ($collection as $product) {
            $text = RecommendationProvider::buildProductText(
                (string) $product->getName(),
                (string) $product->getDescription(),
                (string) $product->getShortDescription(),
                ''
            );

            $embedding = $this->embeddingService->getEmbedding($text);

            if (empty($embedding)) {
                $failed++;
                $output->writeln("<comment>  Skipped product {$product->getId()} ({$product->getSku()}) — embedding failed.</comment>");
                continue;
            }

            $batch[] = [
                'product_id' => (int) $product->getId(),
                'store_id' => $storeId,
                'language' => $language,
                'sku' => (string) $product->getSku(),
                'name' => (string) $product->getName(),
                'updated_at' => date('Y-m-d\TH:i:s'),
                'embedding' => $embedding,
            ];

            $processed++;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->openSearchClient->bulkIndex($batch);
                $output->writeln("  Indexed {$processed}/{$total}...");
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->openSearchClient->bulkIndex($batch);
        }

        $output->writeln("Done for store {$storeId}: {$processed} indexed, {$failed} failed/skipped.");
    }
}