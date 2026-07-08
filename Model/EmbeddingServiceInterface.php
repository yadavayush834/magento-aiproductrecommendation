<?php
namespace Custom\AiProductRecommendation\Model;

interface EmbeddingServiceInterface
{
    /**
     * Generate a vector embedding for the given text
     *
     * @param string $text
     * @return float[]
     */
    public function getEmbedding(string $text): array;
}
