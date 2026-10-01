<?php

use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\IncompleteGenerationException;
use App\Services\ChatbotStudio\StudioAiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['chatbot_studio.provider' => 'deepseek', 'chatbot_studio.deepseek_api_key' => 'deepseek-test-secret',
        'chatbot_studio.api_key' => 'gemini-test-secret', 'chatbot_studio.paid_usage_confirmed' => true,
        'chatbot_studio.free_tier_confirmed' => false, 'chatbot_studio.model' => 'deepseek-flash']);
    Http::preventStrayRequests();
    Sleep::fake();
});

function deepseekStudioResponse(string $content = '{"ready":true}', string $reason = 'stop'): array
{
    return ['choices' => [['finish_reason' => $reason, 'message' => ['content' => $content, 'reasoning_content' => 'Do not expose reasoning']]],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30, 'total_tokens' => 130, 'prompt_cache_hit_tokens' => 40]];
}

it('uses only the DeepSeek endpoint and key with JSON mode and an explicit schema', function () {
    Http::fake(['api.deepseek.com/*' => Http::response(deepseekStudioResponse())]);
    $service = app(StudioAiService::class);
    $schema = ['type' => 'object', 'properties' => ['ready' => ['type' => 'boolean']], 'required' => ['ready']];
    expect($service->chat('Build flows', 'Source', ['schema' => $schema, 'max_tokens' => 500]))->toBe('{"ready":true}');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.deepseek.com/chat/completions'
        && $r->hasHeader('Authorization', 'Bearer deepseek-test-secret') && !$r->hasHeader('x-goog-api-key')
        && $r['model'] === 'deepseek-flash' && $r['max_tokens'] === 500
        && $r['response_format']['type'] === 'json_object' && $r['thinking']['type'] === 'disabled'
        && $r['stream'] === false && str_contains($r['messages'][0]['content'], json_encode($schema))
        && $r['messages'][1]['content'] === 'Source');
    expect($service->configuration())->toMatchArray(['provider' => 'DeepSeek API', 'configured' => true, 'free_tier_confirmed' => false]);
    expect(json_encode($service->configuration()))->not->toContain('deepseek-test-secret', 'gemini-test-secret');
});

it('requires explicit paid consent and a separate key and rejects cross-provider models', function (array $override) {
    config($override);
    expect(app(StudioAiService::class)->configuration()['configured'])->toBeFalse();
    expect(fn () => app(StudioAiService::class)->chat('System', 'Source'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    [['chatbot_studio.paid_usage_confirmed' => false, 'chatbot_studio.free_tier_confirmed' => true]],
    [['chatbot_studio.deepseek_api_key' => '']],
    [['chatbot_studio.model' => 'gemini-3.8-flash']],
    [['chatbot_studio.provider' => 'unknown']],
]);

it('reports sanitized provider errors without retries or fallback for permanent failures', function (int $status, string $message) {
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => ['message' => 'deepseek-test-secret private source']], $status)]);
    try {
        app(StudioAiService::class)->chat('System', 'Source');
        test()->fail('Expected an error');
    } catch (ValidationException $e) {
        expect($e->errors()['generation'][0])->toContain('DeepSeek', $message)->not->toContain('deepseek-test-secret', 'private source', 'Gemini', 'free quota');
    }
    Http::assertSentCount(1);
})->with([[401, 'HTTP 401'], [402, 'balance'], [429, 'rate-limited']]);

it('retries temporary DeepSeek failures with the same model only', function () {
    Http::fake(['api.deepseek.com/*' => Http::sequence()->push([], 503)->push(deepseekStudioResponse())]);
    expect(app(StudioAiService::class)->chat('System', 'Source'))->toBe('{"ready":true}');
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->url())->toBe('https://api.deepseek.com/chat/completions');
        expect($request['model'])->toBe('deepseek-flash');
    }
});

it('maps incomplete DeepSeek results to the existing recovery reasons', function (string $finish, string $content, string $reason) {
    Http::fake(['api.deepseek.com/*' => Http::response(deepseekStudioResponse($content, $finish))]);
    try {
        app(StudioAiService::class)->chat('System', 'Source');
        test()->fail('Expected incomplete output');
    } catch (IncompleteGenerationException $e) {
        expect($e->reason)->toBe($reason);
        expect($e->errors()['generation'][0])->toContain('DeepSeek')->not->toContain('Do not expose reasoning');
    }
})->with([['length', '{}', 'MAX_TOKENS'], ['content_filter', '', 'SAFETY'], ['stop', '', 'EMPTY_OUTPUT'], ['tool_calls', '', 'UNKNOWN']]);

it('sanitizes DeepSeek connection failures', function () {
    Http::fake(['api.deepseek.com/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('deepseek-test-secret source')]);
    try {
        app(StudioAiService::class)->chat('System', 'Source');
        test()->fail('Expected connection failure');
    } catch (ValidationException $e) {
        expect($e->errors()['generation'][0])->toContain('DeepSeek could not be reached')->not->toContain('deepseek-test-secret');
    }
});

it('still repairs malformed JSON and applies blueprint normalization with DeepSeek', function () {
    $blueprint = require __DIR__.'/../Fixtures/ChatbotStudioMixedBlueprint.php';
    Http::fake(['api.deepseek.com/*' => Http::sequence()->push(deepseekStudioResponse('not JSON'))->push(deepseekStudioResponse(json_encode($blueprint)))]);
    $result = app(BlueprintGenerator::class)->generate('Synthetic source', 'Build');
    expect($result['adjustments'])->not->toBeEmpty();
    expect($result['start'])->toBe($blueprint['start']);
    Http::assertSentCount(2);
});
