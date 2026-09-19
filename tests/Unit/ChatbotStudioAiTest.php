<?php

use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\StudioAiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['chatbot_studio.api_key' => 'private-test-key', 'chatbot_studio.free_tier_confirmed' => true, 'chatbot_studio.model' => 'gemini-3.8-flash']);
    Http::preventStrayRequests();
    Sleep::fake();
});

function studioAiResponse(string $content, string $finishReason = 'STOP'): array
{
    return ['candidates' => [['finishReason' => $finishReason, 'content' => ['parts' => [['text' => $content]]]]]];
}

it('sends requests only to the cloud endpoint and keeps the key out of URLs and browser configuration', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioAiResponse('{"ok":true}'))]);
    $service = app(StudioAiService::class);
    expect($service->chat('System instructions', 'User source'))->toBe('{"ok":true}');
    Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent'
        && $request->hasHeader('x-goog-api-key', 'private-test-key')
        && $request['contents'][0]['parts'][0]['text'] === 'User source'
        && $request['generationConfig']['responseMimeType'] === 'application/json');
    expect(json_encode($service->configuration()))->not->toContain('private-test-key');
});

it('does not call any service without explicit free-tier configuration', function (string $missing) {
    config(['chatbot_studio.'.$missing => $missing === 'api_key' ? '' : false]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['api_key', 'free_tier_confirmed']);

it('refuses non-allowlisted media agent embedding paid-only and retired models', function (string $model) {
    config(['chatbot_studio.model' => $model]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['a-paid-only-model', 'gemini-3.1-pro-preview', 'gemini-2.5-pro',
    'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-3-pro-image',
    'gemini-2.5-flash-preview-tts', 'gemini-3.8-live', 'gemini-embedding-2',
    'veo-3.1-generate-preview', 'lyria-3-clip-preview', 'deep-research-pro-preview']);

it('supports explicitly selecting an allowed alternative without automatic fallback', function (string $model) {
    config(['chatbot_studio.model' => $model]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioAiResponse('{"ready":true}'))]);
    expect(app(StudioAiService::class)->chat('system', 'source'))->toBe('{"ready":true}');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.$model.':generateContent'));
    Http::assertSentCount(1);
})->with(['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash',
    'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite', 'gemini-3-flash-preview',
    'gemini-2.5-flash', 'gemini-2.5-flash-lite', 'gemma-4-26b-a4b-it', 'gemma-4-31b-it']);

it('stops at the free quota limit without automatic retries or paid or local fallback', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
    expect(fn () => app(BlueprintGenerator::class)->generate('source', 'build bot'))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

it('recovers from temporary service errors using the same model and source', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
        ->push(['error' => ['status' => 'UNAVAILABLE']], 503)
        ->push(studioAiResponse('{"ok":true}'))]);
    expect(app(StudioAiService::class)->chat('system', 'original source'))->toBe('{"ok":true}');
    Http::assertSentCount(3);
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toEndWith('/gemini-3.8-flash:generateContent');
        expect($request['contents'][0]['parts'][0]['text'])->toBe('original source');
    }
});

it('stops temporary service retries at three attempts with a sanitized actionable error', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'private-test-key sensitive upstream details']], 503)]);
    try {
        app(BlueprintGenerator::class)->generate('source', 'build bot');
        test()->fail('Expected service-unavailable error');
    } catch (ValidationException $e) {
        $message = $e->errors()['generation'][0];
        expect($message)->toContain('temporarily unavailable', 'HTTP 503')->not->toContain('private-test-key', 'sensitive upstream details', 'Check the API key');
    }
    Http::assertSentCount(3);
});

it('does not retry when the request time budget is exhausted', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => function () {
        usleep(20000);
        return Http::response([], 503);
    }]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source', ['timeout' => 0.01]))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

it('does not retry permanent request errors', function (int $status) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], $status)]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
})->with([400, 401, 403, 404]);

it('classifies connection failures without disclosing upstream secrets or retrying blindly', function (int $errno, string $reason) {
    Illuminate\Support\Facades\Log::spy();
    $request = new GuzzleHttp\Psr7\Request('POST', 'https://generativelanguage.googleapis.com');
    $cause = new GuzzleHttp\Exception\ConnectException('private-test-key sensitive source', $request, null, ['errno' => $errno]);
    Http::fake(['generativelanguage.googleapis.com/*' => function () use ($cause) {
        throw new Illuminate\Http\Client\ConnectionException('private-test-key sensitive source', 0, $cause);
    }]);
    try {
        app(StudioAiService::class)->chat('system', 'sensitive source');
        test()->fail('Expected connection failure');
    } catch (ValidationException $e) {
        expect($e->errors()['generation'][0])->toContain($reason)->not->toContain('private-test-key', 'sensitive source');
    }
    Illuminate\Support\Facades\Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message, $context) =>
        $context['curl_errno'] === $errno && $context['reason'] === $reason
        && !str_contains(json_encode($context), 'private-test-key') && !str_contains(json_encode($context), 'sensitive source'));
})->with([[28, 'TIMEOUT'], [6, 'DNS'], [60, 'TLS'], [7, 'CONNECT']]);

it('rejects truncated or blocked model outputs', function (string $reason) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioAiResponse('{"partial":true}', $reason))]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
})->with(['MAX_TOKENS', 'SAFETY', 'RECITATION']);

it('reports the exact finish reason without logging source text or API keys', function () {
    Illuminate\Support\Facades\Log::spy();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(array_merge(
        studioAiResponse('sensitive incomplete output', 'MAX_TOKENS'),
        ['usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 24, 'thoughtsTokenCount' => 8]]
    ))]);
    try {
        app(StudioAiService::class)->chat('system', 'sensitive source');
        test()->fail('Expected incomplete generation');
    } catch (App\Services\ChatbotStudio\IncompleteGenerationException $e) {
        expect($e->reason)->toBe('MAX_TOKENS');
        expect($e->errors()['generation'][0])->toContain('MAX_TOKENS')->not->toContain('sensitive', 'private-test-key');
    }
    Illuminate\Support\Facades\Log::shouldHaveReceived('notice')->once()->withArgs(function ($message, $context) {
        return $context['finish_reason'] === 'MAX_TOKENS' && $context['output_tokens'] === 24
            && !str_contains(json_encode($context), 'sensitive') && !str_contains(json_encode($context), 'private-test-key');
    });
});

it('rejects an empty successful candidate and reports prompt blocking', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioAiResponse(''))
        ->push(['promptFeedback' => ['blockReason' => 'SAFETY']])]);
    foreach (['EMPTY_OUTPUT', 'SAFETY'] as $reason) {
        try {
            app(StudioAiService::class)->chat('system', 'source');
            test()->fail('Expected incomplete generation');
        } catch (App\Services\ChatbotStudio\IncompleteGenerationException $e) {
            expect($e->reason)->toBe($reason);
        }
    }
});

it('uses structured output and recommended Gemini 3 temperature with bounded output tokens', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioAiResponse('{}'))]);
    app(StudioAiService::class)->chat('system', 'source', ['schema' => App\Services\ChatbotStudio\BlueprintSchema::blueprint(), 'max_tokens' => 999999, 'temperature' => 0.1]);
    Http::assertSent(fn ($request) => $request['generationConfig']['maxOutputTokens'] === 65536
        && $request['generationConfig']['temperature'] == 1
        && isset($request['generationConfig']['responseJsonSchema']['properties']['screens']));
});

it('repairs invalid structured output using the original source without publishing anything', function () {
    $blueprint = ['name' => 'Hello bot', 'start' => 'welcome', 'trigger' => '000', 'issues' => [], 'screens' => [
        ['key' => 'welcome', 'name' => 'Welcome', 'messages' => ['Exact original text'], 'questions' => [], 'choices' => []],
    ]];
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioAiResponse('{"broken":true}'))
        ->push(studioAiResponse(json_encode($blueprint)))]);
    expect(app(BlueprintGenerator::class)->generate('Exact original text', 'Build it'))->toBe($blueprint);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], 'repair_errors')
        && str_contains($request['contents'][0]['parts'][0]['text'], 'Exact original text'));
});
