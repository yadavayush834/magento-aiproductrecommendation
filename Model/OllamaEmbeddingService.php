<?php
namespace Custom\AiProductRecommendation\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates embeddings using Ollama (local AI — used in development)
 * For production, swap this with BedrockEmbeddingService via di.xml
 */
class OllamaEmbeddingService implements EmbeddingServiceInterface
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
     * Call Ollama API to generate a vector embedding for the given text
     */
    public function getEmbedding(string $text): array
    {
        $host = $this->config->getValue('ai_recommendation/ollama/host') ?? 'localhost';
        $port = $this->config->getValue('ai_recommendation/ollama/port') ?? '11434';
        $model = $this->config->getValue('ai_recommendation/ollama/model') ?? 'nomic-embed-text';

        $url = "http://{$host}:{$port}/api/embeddings";

        $payload = json_encode([
            'model' => $model,
            'prompt' => $text
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->logger->error('Ollama embedding failed', [
                'http_code' => $httpCode,
                'text_preview' => substr($text, 0, 100)
            ]);
            return [];
        }

        $data = json_decode($response, true);

        if (!isset($data['embedding']) || !is_array($data['embedding'])) {
            $this->logger->error('Ollama returned unexpected response', ['response' => $response]);
            return [];
        }

        return $data['embedding'];
    }
}
