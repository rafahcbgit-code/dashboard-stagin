<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected string $endpoint;
    protected string $apiKey;
    protected string $model;
    protected int $timeout;
    protected int $maxTokens;
    protected float $temperature;

    /** In-memory cache for identical prompts within a single request */
    protected array $cache = [];

    public function __construct()
    {
        $this->endpoint = config('ai.endpoint');
        $this->apiKey = config('ai.api_key');
        $this->model = config('ai.model', 'gemma3:4b');
        $this->timeout = config('ai.timeout', 60);
        $this->maxTokens = config('ai.max_tokens', 500);
        $this->temperature = config('ai.temperature', 0.30);
    }

    /**
     * Send a chat message to the AI and get a response.
     *
     * @param string $systemPrompt System-level instructions
     * @param string $userMessage The user message to classify/respond to
     * @param array $options Override defaults: model, temperature, max_tokens, timeout
     * @return AiResponse
     */
    public function chat(string $systemPrompt, string $userMessage, array $options = []): AiResponse
    {
        $cacheKey = md5($systemPrompt . '|' . $userMessage . '|' . json_encode($options));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $model = $options['model'] ?? $this->model;
        $temperature = $options['temperature'] ?? $this->temperature;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;
        $timeout = $options['timeout'] ?? $this->timeout;

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
                'num_predict' => $maxTokens,
            ],
        ];

        try {
            $response = $this->makeRequest($payload, $timeout);

            if (!$response->successful()) {
                Log::error('AiService: Request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return new AiResponse(
                    content: '',
                    confidence: 0,
                    model: $model,
                    success: false,
                    error: "AI request failed with status {$response->status()}"
                );
            }

            $data = $response->json();
            $content = $data['message']['content'] ?? '';

            $aiResponse = new AiResponse(
                content: trim($content),
                confidence: 0,
                model: $data['model'] ?? $model,
                success: true,
                error: null
            );

            $this->cache[$cacheKey] = $aiResponse;
            return $aiResponse;

        } catch (\Exception $e) {
            Log::error('AiService: Exception', [
                'message' => $e->getMessage(),
                'model' => $model,
            ]);

            return new AiResponse(
                content: '',
                confidence: 0,
                model: $model,
                success: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Classify a message against a list of intents.
     *
     * @param string $message The incoming user message
     * @param array $intents List of intent labels to classify against
     * @param float $threshold Confidence threshold (0.0 - 1.0)
     * @param array $options Override defaults
     * @return IntentMatch|null Returns the best matching intent, or null if no match above threshold
     */
    public function classifyIntent(string $message, array $intents, float $threshold = 0.75, array $options = []): ?IntentMatch
    {
        if (empty($intents)) {
            return null;
        }

        $intentList = implode("\n- ", $intents);

        $systemPrompt = <<<'PROMPT'
You are a message intent classifier. You must respond ONLY with valid JSON in this exact format:
{"match": "yes" or "no", "intent": "<the_matched_intent_or_none>", "confidence": <0.0_to_1.0>}

Rules:
- Match the user's message to the most relevant intent from the provided list.
- If the message does not clearly match any intent, respond with match: "no" and intent: "none".
- Be strict: only match if the message's intent clearly aligns with one of the listed intents.
- Consider synonyms, paraphrases, and natural language variations.
PROMPT;

        $userPrompt = "Classify this message against these intents:\n- {$intentList}\n\nMessage: \"{$message}\"";

        $response = $this->chat($systemPrompt, $userPrompt, $options);

        if (!$response->success || empty($response->content)) {
            return null;
        }

        $parsed = $this->parseJsonResponse($response->content);

        if ($parsed === null) {
            Log::warning('AiService: Failed to parse intent classification response', [
                'raw_content' => $response->content,
            ]);
            return null;
        }

        $match = $parsed['match'] ?? 'no';
        $intent = $parsed['intent'] ?? 'none';
        $confidence = (float) ($parsed['confidence'] ?? 0);

        if ($match !== 'yes' || $intent === 'none' || $confidence < $threshold) {
            return null;
        }

        // Verify the matched intent is in the provided list
        if (!in_array(strtolower($intent), array_map('strtolower', $intents))) {
            // Try case-insensitive matching
            $matched = false;
            foreach ($intents as $validIntent) {
                if (strtolower($intent) === strtolower($validIntent)) {
                    $intent = $validIntent;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                Log::info('AiService: AI matched intent not in provided list', [
                    'matched_intent' => $intent,
                    'valid_intents' => $intents,
                ]);
                return null;
            }
        }

        return new IntentMatch(
            intent: $intent,
            confidence: $confidence,
            rawResponse: $parsed
        );
    }

    /**
     * Simple one-shot query to the AI.
     */
    public function query(string $prompt, array $options = []): string
    {
        $response = $this->chat('You are a helpful assistant.', $prompt, $options);
        return $response->success ? $response->content : '';
    }

    /**
     * Make the HTTP request to the AI endpoint.
     */
    protected function makeRequest(array $payload, int $timeout)
    {
        $url = rtrim($this->endpoint, '/') . '/chat';

        $http = Http::timeout($timeout)
            ->acceptJson()
            ->contentType('application/json');

        if (!empty($this->apiKey)) {
            $http = $http->withHeaders(['Authorization' => 'Bearer ' . $this->apiKey]);
        }

        return $http->post($url, $payload);
    }

    /**
     * Parse a JSON response from the AI, handling markdown code blocks.
     */
    protected function parseJsonResponse(string $content): ?array
    {
        // Strip markdown code blocks if present
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to extract JSON from the content
        if (preg_match('/\{[^{}]*"match"[^{}]*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return null;
    }
}

/**
 * Data class for AI chat responses.
 */
class AiResponse
{
    public function __construct(
        public string $content,
        public float $confidence,
        public string $model,
        public bool $success,
        public ?string $error,
    ) {}
}

/**
 * Data class for intent classification results.
 */
class IntentMatch
{
    public function __construct(
        public string $intent,
        public float $confidence,
        public array $rawResponse,
    ) {}
}