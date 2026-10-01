<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

class StudioAiService
{
    public function configuration(): array
    {
        $deepseek = $this->provider() === 'deepseek';
        return ['provider' => $deepseek ? 'DeepSeek API' : 'Gemini API', 'provider_id' => $this->provider(),
            'model' => config('chatbot_studio.model'),
            'configured' => in_array($this->provider(), ['gemini', 'deepseek'], true)
                && trim($this->apiKey()) !== ''
                && (bool) config('chatbot_studio.'.($deepseek ? 'paid_usage_confirmed' : 'free_tier_confirmed'))
                && in_array(config('chatbot_studio.model'), $this->allowedModels(), true),
            'free_tier_confirmed' => !$deepseek && (bool) config('chatbot_studio.free_tier_confirmed'),
            'paid_usage_confirmed' => $deepseek && (bool) config('chatbot_studio.paid_usage_confirmed')];
    }

    public function provider(): string
    {
        return (string) config('chatbot_studio.provider', 'gemini');
    }

    public function allowedModels(): array
    {
        return config('chatbot_studio.'.($this->provider() === 'deepseek' ? 'deepseek_allowed_models' : 'allowed_models'), []);
    }

    private function apiKey(): string
    {
        return (string) config('chatbot_studio.'.($this->provider() === 'deepseek' ? 'deepseek_api_key' : 'api_key'), '');
    }

    public function assertConfigured(): void
    {
        if (!in_array($this->provider(), ['gemini', 'deepseek'], true)) {
            throw ValidationException::withMessages(['generation' => 'Unsupported Chatbot Studio provider. Select gemini or deepseek explicitly.']);
        }
        if (!in_array(config('chatbot_studio.model'), $this->allowedModels(), true)) {
            throw ValidationException::withMessages(['generation' => 'The configured model is not on the selected Chatbot Studio provider allowlist.']);
        }
        if (!$this->configuration()['configured']) {
            throw ValidationException::withMessages(['generation' => $this->provider() === 'deepseek'
                ? 'Configure CHATBOT_STUDIO_DEEPSEEK_API_KEY and explicitly set CHATBOT_STUDIO_PAID_USAGE_CONFIRMED=true. DeepSeek API usage is billed.'
                : 'Configure CHATBOT_STUDIO_GEMINI_API_KEY from a Free Tier project with billing disabled, and set CHATBOT_STUDIO_FREE_TIER_CONFIRMED=true.']);
        }
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): string
    {
        $this->assertConfigured();
        $model = config('chatbot_studio.model');
        $deepseek = $this->provider() === 'deepseek';
        $providerName = $deepseek ? 'DeepSeek' : 'Gemini';
        $generationConfig = [
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => min(65536, max(256, (int) ($options['max_tokens'] ?? config('chatbot_studio.output_tokens')))),
            'temperature' => str_starts_with($model, 'gemini-3') ? 1.0 : ($options['temperature'] ?? 0.1),
        ];
        if (isset($options['schema'])) {
            $generationConfig['responseJsonSchema'] = $options['schema'];
        }
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent';
        $headers = ['x-goog-api-key' => $this->apiKey()];
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'generationConfig' => $generationConfig,
        ];
        if ($deepseek) {
            $endpoint = 'https://api.deepseek.com/chat/completions';
            $headers = ['Authorization' => 'Bearer '.$this->apiKey()];
            // JSON mode is not schema enforcement. Compiler validation/repair remains mandatory.
            $systemPrompt .= "\nReturn only a JSON object, without Markdown.";
            if (isset($options['schema'])) {
                $systemPrompt .= "\nRequired JSON schema:\n".json_encode($options['schema'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }
            $payload = ['model' => $model, 'stream' => false, 'thinking' => ['type' => 'disabled'],
                'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userMessage]],
                'response_format' => ['type' => 'json_object'], 'max_tokens' => $generationConfig['maxOutputTokens'],
                'temperature' => $options['temperature'] ?? 0.1];
        }
        // Retries share one request budget so JSON repair still fits the queue timeout.
        $budget = max(0.001, min(240, (float) ($options['timeout'] ?? config('chatbot_studio.request_timeout'))));
        $deadline = hrtime(true) / 1e9 + $budget;
        // Keep the key in a header, never in a URL, a browser payload, or logs.
        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $remaining = $deadline - hrtime(true) / 1e9;
                if ($remaining <= 0) {
                    break;
                }
                if (!empty($options['usage_draft_id'])) {
                    app(TokenUsageTracker::class)->started($options['usage_draft_id']);
                }
                $response = Http::acceptJson()->asJson()->timeout($remaining)
                    ->connectTimeout(min(15, $remaining))->withOptions(['allow_redirects' => false])
                    ->withHeaders($headers)->post($endpoint, $payload);
                $usage = $deepseek ? $this->deepseekUsage($response->json('usage')) : $response->json('usageMetadata');
                if (!empty($options['usage_draft_id'])) {
                    app(TokenUsageTracker::class)->completed($options['usage_draft_id'], $usage);
                }
                if (!in_array($response->status(), [500, 502, 503, 504], true) || $attempt === 2) {
                    break;
                }
                $delayMs = (1000 * (2 ** $attempt)) + random_int(0, 250);
                if ($deadline - hrtime(true) / 1e9 <= $delayMs / 1000 + 1) {
                    break;
                }
                Sleep::for($delayMs)->milliseconds();
            }
        } catch (ConnectionException $e) {
            $details = $this->connectionFailureDetails($e);
            Log::notice('Chatbot Studio connection failed', array_merge($details, [
                'model' => $model, 'request_budget_seconds' => $budget,
            ]));
            $message = match ($details['reason']) {
                'TIMEOUT' => 'Gemini request timed out (TIMEOUT). The draft is preserved. Check worker logs and retry later.',
                'DNS' => 'Gemini hostname could not be resolved (DNS). Check the production server DNS settings.',
                'TLS' => 'Gemini secure connection failed (TLS). Check the production server CA certificates and clock; do not disable certificate verification.',
                'CONNECT' => 'Gemini connection failed (CONNECT). Check outbound HTTPS access from the production server.',
                default => 'Gemini could not be reached. The draft is preserved; retry later.',
            };
            throw ValidationException::withMessages(['generation' => str_replace('Gemini', $providerName, $message)]);
        }
        if (!isset($response) || in_array($response->status(), [500, 502, 503, 504], true)) {
            throw ValidationException::withMessages(['generation' => $providerName.' is temporarily unavailable'.(isset($response) ? ' (HTTP '.$response->status().')' : '').'. Please wait a few minutes and generate again. The draft is preserved; no model or provider was changed.']);
        }
        if ($response->status() === 429) {
            throw ValidationException::withMessages(['generation' => $deepseek
                ? 'DeepSeek is rate-limited (HTTP 429). Wait before retrying. No model or provider was changed.'
                : 'Gemini quota is exhausted or rate-limited. Retry after your free quota resets. No paid or local fallback was used.']);
        }
        if ($deepseek && $response->status() === 402) {
            throw ValidationException::withMessages(['generation' => 'DeepSeek balance is insufficient (HTTP 402). Check your API account balance. The draft is preserved.']);
        }
        if (!$response->successful()) {
            throw ValidationException::withMessages(['generation' => $providerName.' rejected the request (HTTP '.$response->status().'). Check the API key, model availability and supported region.']);
        }
        $candidate = $response->json('candidates.0');
        if ($deepseek) {
            $choice = $response->json('choices.0');
            $candidate = is_array($choice) ? ['finishReason' => match ($choice['finish_reason'] ?? null) {
                'stop' => 'STOP', 'length' => 'MAX_TOKENS', 'content_filter' => 'SAFETY',
                'insufficient_system_resource' => 'OTHER', default => 'UNKNOWN',
            }, 'content' => ['parts' => [['text' => is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '']]]] : null;
        }
        if (!$candidate || ($candidate['finishReason'] ?? '') !== 'STOP') {
            $reason = $candidate['finishReason'] ?? $response->json('promptFeedback.blockReason') ?? 'NO_CANDIDATE';
            $reason = is_string($reason) && preg_match('/^[A-Z_]{1,80}$/', $reason) ? $reason : 'UNKNOWN';
            Log::notice('Chatbot Studio incomplete generation', [
                'model' => $model, 'finish_reason' => $reason,
                'output_limit' => $generationConfig['maxOutputTokens'],
                'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
                'thinking_tokens' => (int) ($usage['thoughtsTokenCount'] ?? 0),
            ]);
            $message = match ($reason) {
                'MAX_TOKENS' => 'Gemini reached the output limit (MAX_TOKENS). The draft is preserved; retry to recover in smaller batches.',
                'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII' => 'Gemini blocked the response ('.$reason.'). Review the source for sensitive or restricted content. No chatbot was published.',
                default => 'Gemini returned no complete result ('.$reason.'). The draft is preserved. Retry generation; contact support with this reason if it persists.',
            };
            throw new IncompleteGenerationException($reason, str_replace('Gemini', $providerName, $message));
        }
        $parts = array_filter($candidate['content']['parts'] ?? [], fn ($part) => isset($part['text']) && empty($part['thought']));
        $text = implode('', array_column($parts, 'text'));
        if (trim($text) === '') {
            throw new IncompleteGenerationException('EMPTY_OUTPUT', $providerName.' returned an empty result (EMPTY_OUTPUT). The draft is preserved; retry generation.');
        }
        return $text;
    }

    private function deepseekUsage(mixed $usage): ?array
    {
        if (!is_array($usage)) {
            return null;
        }
        $completion = $usage['completion_tokens'] ?? null;
        $reasoning = $usage['completion_tokens_details']['reasoning_tokens'] ?? 0;
        $reasoning = is_int($completion) && is_int($reasoning) ? min(max(0, $reasoning), max(0, $completion)) : 0;
        // Completion includes reasoning; avoid double counting in the existing UI breakdown.
        return ['totalTokenCount' => $usage['total_tokens'] ?? null, 'promptTokenCount' => $usage['prompt_tokens'] ?? null,
            'candidatesTokenCount' => is_int($completion) ? max(0, $completion - $reasoning) : null,
            'thoughtsTokenCount' => $reasoning,
            'cachedContentTokenCount' => $usage['prompt_cache_hit_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? 0];
    }

    public function connectionFailureDetails(ConnectionException $exception): array
    {
        $errno = null;
        for ($error = $exception; $error; $error = $error->getPrevious()) {
            if ($error instanceof \GuzzleHttp\Exception\ConnectException || $error instanceof \GuzzleHttp\Exception\RequestException) {
                $value = $error->getHandlerContext()['errno'] ?? null;
                if (is_int($value)) {
                    $errno = $value;
                    break;
                }
            }
        }
        return ['curl_errno' => $errno, 'reason' => match ($errno) {
            28 => 'TIMEOUT', 5, 6 => 'DNS', 35, 51, 58, 60, 77, 83, 90, 91 => 'TLS', 7 => 'CONNECT',
            default => 'CONNECTION',
        }];
    }
}
