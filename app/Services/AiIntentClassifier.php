<?php

namespace App\Services;

class AiIntentClassifier
{
    protected AiService $aiService;

    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Classify a message against keywords using the trigger-level match mode.
     *
     * @param string $message The incoming user message
     * @param array $keywords Array of keyword strings: ["refund", "help", "cancel"]
     * @param string $matchMode Match mode for all keywords: 'exact' or 'ai'
     * @param int $aiConfidenceThreshold AI confidence threshold (0-100), used when matchMode is 'ai'
     * @return IntentMatchResult|null The matched keyword, or null if no match
     */
    public function classify(string $message, array $keywords, string $matchMode = 'exact', int $aiConfidenceThreshold = 75): ?IntentMatchResult
    {
        if (empty($keywords)) {
            return null;
        }

        // Filter out empty keywords
        $keywords = array_filter($keywords, fn($kw) => !empty(trim($kw)));

        if (empty($keywords)) {
            return null;
        }

        if ($matchMode === 'ai') {
            return $this->matchAi($message, $keywords, $aiConfidenceThreshold);
        }

        // Default: exact match (no AI call)
        $match = $this->matchExact($message, $keywords);
        if ($match !== null) {
            return new IntentMatchResult(
                matchedKeyword: $match,
                matchMode: 'exact',
                confidence: 1.0
            );
        }

        return null;
    }

    /**
     * Exact match: message exactly equals a keyword (case-insensitive for Latin, case-sensitive for Arabic).
     */
    protected function matchExact(string $message, array $keywords): ?string
    {
        $normalizedMessage = $this->normalizeForExact($message);

        foreach ($keywords as $keyword) {
            $normalizedKeyword = $this->normalizeForExact($keyword);

            if ($normalizedMessage === $normalizedKeyword) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * AI match: use Ollama to classify intent against keywords.
     */
    protected function matchAi(string $message, array $keywords, int $threshold): ?IntentMatchResult
    {
        $thresholdDecimal = $threshold / 100;
        $result = $this->aiService->classifyIntent($message, $keywords, $thresholdDecimal);

        if ($result === null) {
            return null;
        }

        return new IntentMatchResult(
            matchedKeyword: $result->intent,
            matchMode: 'ai',
            confidence: $result->confidence
        );
    }

    /**
     * Normalize a string for exact comparison.
     * Lowercase for Latin scripts, preserve case for Arabic/RTL scripts.
     */
    protected function normalizeForExact(string $text): string
    {
        $text = trim($text);

        // Keep case for Arabic
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return $text;
        }

        return mb_strtolower($text, 'UTF-8');
    }
}

/**
 * Data class for intent classification results.
 */
class IntentMatchResult
{
    public function __construct(
        public string $matchedKeyword,
        public string $matchMode, // 'exact' or 'ai'
        public float $confidence, // 0.0 - 1.0
    ) {}
}