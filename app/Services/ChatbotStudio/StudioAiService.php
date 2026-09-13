<?php

namespace App\Services\ChatbotStudio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
                $response = Http::acceptJson()->asJson()->timeout($remaining)
                    ->connectTimeout(min(15, $remaining))->withOptions(['allow_redirects' => false])
                    ->withHeaders(['x-goog-api-key' => config('chatbot_studio.api_key')])
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent', [
                        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
                        'generationConfig' => ['responseMimeType' => 'application/json',
                            'maxOutputTokens' => $options['max_tokens'] ?? config('chatbot_studio.output_tokens'),
                            'temperature' => $options['temperature'] ?? 0.1],
                    ]);
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
            throw ValidationException::withMessages(['generation' => 'Gemini could not be reached. The draft is preserved; retry later.']);
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
            throw ValidationException::withMessages(['generation' => 'Gemini did not complete the blueprint. No partial chatbot was saved. Review the source or output limit before retrying.']);
        }
        $parts = array_filter($candidate['content']['parts'] ?? [], fn ($part) => isset($part['text']) && empty($part['thought']));
        $text = implode('', array_column($parts, 'text'));
        return $text;
    }
}
