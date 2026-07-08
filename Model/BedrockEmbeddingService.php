<?php
namespace Custom\AiProductRecommendation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates embeddings using AWS Bedrock Titan Embeddings V2
 * Used in production — swap in via di.xml preference
 *
 * Requires: AWS SDK for PHP installed via composer
 * composer require aws/aws-sdk-php
 */
class BedrockEmbeddingService implements EmbeddingServiceInterface
{
    private ScopeConfigInterface $config;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Call AWS Bedrock Titan Embeddings V2 to generate vector
     */
    public function getEmbedding(string $text): array
    {
        try {
            // AWS SDK must be installed
            $client = new \Aws\BedrockRuntime\BedrockRuntimeClient([
                'region'  => 'ap-south-1', // Mumbai — closest for India
                'version' => 'latest',
                // Credentials auto-detected from EC2 IAM role in production
            ]);

            $result = $client->invokeModel([
                'modelId'     => 'amazon.titan-embed-text-v2:0',
                'contentType' => 'application/json',
                'accept'      => 'application/json',
                'body'        => json_encode(['inputText' => $text]),
            ]);

            $body = json_decode($result['body']->getContents(), true);

            if (!isset($body['embedding'])) {
                $this->logger->error('Bedrock returned no embedding');
                return [];
            }

            return $body['embedding'];

        } catch (\Exception $e) {
            $this->logger->error('Bedrock embedding error: ' . $e->getMessage());
            return [];
        }
    }
}
