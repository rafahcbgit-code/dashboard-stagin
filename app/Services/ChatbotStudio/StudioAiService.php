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
        return ['provider' => 'Gemini API', 'model' => config('chatbot_studio.model'),
            'configured' => trim(config('chatbot_studio.api_key', '')) !== '' && config('chatbot_studio.free_tier_confirmed'),
            'free_tier_confirmed' => config('chatbot_studio.free_tier_confirmed')];
    }

    public function assertConfigured(): void
    {
        if (!$this->configuration()['configured']) {
            throw ValidationException::withMessages(['generation' => 'Configure CHATBOT_STUDIO_GEMINI_API_KEY from a Free Tier project with billing disabled, and set CHATBOT_STUDIO_FREE_TIER_CONFIRMED=true.']);
        }
        if (!in_array(config('chatbot_studio.model'), config('chatbot_studio.allowed_models'), true)) {
            throw ValidationException::withMessages(['generation' => 'The configured model is not on the Chatbot Studio Free Tier allowlist.']);
        }
    }

    public function chat(string $systemPrompt, string $userMessage, array $options = []): string
    {
        $this->assertConfigured();
        $model = config('chatbot_studio.model');
        $generationConfig = [
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => min(65536, max(256, (int) ($options['max_tokens'] ?? config('chatbot_studio.output_tokens')))),
            'temperature' => str_starts_with($model, 'gemini-3') ? 1.0 : ($options['temperature'] ?? 0.1),
        ];
        if (isset($options['schema'])) {
            $generationConfig['responseJsonSchema'] = $options['schema'];
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
                    ->withHeaders(['x-goog-api-key' => config('chatbot_studio.api_key')])
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
                        'generationConfig' => $generationConfig,
                    ]);
                if (!empty($options['usage_draft_id'])) {
                    app(TokenUsageTracker::class)->completed($options['usage_draft_id'], $response->json('usageMetadata'));
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
            throw ValidationException::withMessages(['generation' => $message]);
        }
        if (!isset($response) || in_array($response->status(), [500, 502, 503, 504], true)) {
            throw ValidationException::withMessages(['generation' => 'Gemini is temporarily unavailable'.(isset($response) ? ' (HTTP '.$response->status().')' : '').'. Please wait a few minutes and generate again. The draft is preserved; no model or provider was changed.']);
        }
        if ($response->status() === 429) {
            throw ValidationException::withMessages(['generation' => 'Gemini quota is exhausted or rate-limited. Retry after your free quota resets. No paid or local fallback was used.']);
        }
        if (!$response->successful()) {
            throw ValidationException::withMessages(['generation' => 'Gemini rejected the request (HTTP '.$response->status().'). Check the API key, model availability and supported region.']);
        }
        $candidate = $response->json('candidates.0');
        if (!$candidate || ($candidate['finishReason'] ?? '') !== 'STOP') {
            $reason = $candidate['finishReason'] ?? $response->json('promptFeedback.blockReason') ?? 'NO_CANDIDATE';
            $reason = is_string($reason) && preg_match('/^[A-Z_]{1,80}$/', $reason) ? $reason : 'UNKNOWN';
            $usage = $response->json('usageMetadata', []);
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
            throw new IncompleteGenerationException($reason, $message);
        }
        $parts = array_filter($candidate['content']['parts'] ?? [], fn ($part) => isset($part['text']) && empty($part['thought']));
        $text = implode('', array_column($parts, 'text'));
        if (trim($text) === '') {
            throw new IncompleteGenerationException('EMPTY_OUTPUT', 'Gemini returned an empty result (EMPTY_OUTPUT). The draft is preserved; retry generation.');
        }
        return $text;
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
