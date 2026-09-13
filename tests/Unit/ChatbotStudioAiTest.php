<?php

use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\StudioAiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['chatbot_studio.api_key' => 'private-test-key', 'chatbot_studio.free_tier_confirmed' => true, 'chatbot_studio.model' => 'gemini-3.8-flash']);
    Http::preventStrayRequests();
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

it('refuses non-allowlisted models', function () {
    config(['chatbot_studio.model' => 'a-paid-only-model']);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('stops at the free quota limit without automatic retries or paid or local fallback', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota']], 429)]);
    expect(fn () => app(BlueprintGenerator::class)->generate('source', 'build bot'))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
});

it('rejects truncated or blocked model outputs', function (string $reason) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioAiResponse('{"partial":true}', $reason))]);
    expect(fn () => app(StudioAiService::class)->chat('system', 'source'))->toThrow(ValidationException::class);
})->with(['MAX_TOKENS', 'SAFETY', 'RECITATION']);

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
