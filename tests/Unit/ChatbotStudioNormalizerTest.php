<?php

use App\Services\ChatbotStudio\BlueprintCompiler;
use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\BlueprintNormalizer;
use App\Services\ChatbotStudio\BlueprintRecovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

uses(Tests\TestCase::class);

function mixedStudioBlueprint(): array
{
    return require __DIR__.'/../Fixtures/ChatbotStudioMixedBlueprint.php';
}

function normalizerResponse(array $value): array
{
    return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($value)]]]]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['chatbot_studio.api_key' => 'test-key', 'chatbot_studio.free_tier_confirmed' => true, 'chatbot_studio.model' => 'gemini-3.5-flash-lite']);
});

it('normalizes the bilingual CTA return-button pattern without losing messages destinations or questions', function () {
    $original = mixedStudioBlueprint();
    $normalizer = app(BlueprintNormalizer::class);
    $fixed = app(BlueprintCompiler::class)->validate($normalizer->normalize($original));
    expect($normalizer->normalize($fixed))->toBe($fixed);
    expect($fixed['issues'])->toBe([BlueprintNormalizer::REVIEW_ISSUE]);
    expect(array_column($fixed['adjustments'], 'reason'))->toContain('split_cta', 'distinct_trigger', 'short_label', 'list_title');
    $byKey = array_column($fixed['screens'], null, 'key');
    $messages = array_merge(...array_column($fixed['screens'], 'messages'));
    $questions = array_merge(...array_column($fixed['screens'], 'questions'));
    foreach ($original['screens'] as $screen) {
        expect($byKey)->toHaveKey($screen['key']);
        foreach ($screen['messages'] as $message) {
            expect(count(array_keys($messages, $message, true)))->toBe(1);
        }
        foreach ($screen['questions'] as $question) {
            expect($questions)->toContain($question);
        }
        $end = $byKey[$screen['key']];
        while (!$end['choices'] && !empty($end['next'])) {
            $end = $byKey[$end['next']];
        }
        expect(array_column($end['choices'], 'target'))->toBe(array_column($screen['choices'], 'target'));
    }
    foreach ($fixed['screens'] as $screen) {
        if ($screen['cta'] ?? null) {
            expect($screen['choices'])->toBe([])->and($screen['questions'])->toBe([]);
        }
        foreach (array_merge(array_column($screen['choices'], 'label'), [$screen['list_label'] ?? '', $screen['cta']['label'] ?? '']) as $label) {
            expect(strlen(mb_convert_encoding($label, 'UTF-16LE', 'UTF-8')) / 2)->toBeLessThanOrEqual(20);
        }
    }
    expect($byKey['NODE_01']['list_label'])->toBe('عرض الخيارات');
    expect($byKey['NODE_01_EN']['list_label'])->toBe('View options');
    $compiled = app(BlueprintCompiler::class)->compile($fixed);
    expect($compiled['flows'])->toHaveCount(count($fixed['screens']));
    expect($compiled['flows']['NODE_03']['metadata']['nodes'][1]['data']['metadata']['fields']['ctaUrlButton']['url'])->toBe('https://example.org/store');
    Http::assertNothingSent();
});

it('keeps already valid blueprints unchanged and preserves repeated labels sharing one destination', function () {
    $original = ['name' => 'Valid', 'start' => 'root', 'trigger' => '000', 'issues' => [], 'screens' => [
        ['key' => 'root', 'name' => 'Root', 'messages' => ['Original'], 'questions' => [], 'choices' => [['label' => 'Open', 'target' => 'child']]],
        ['key' => 'child', 'name' => 'Child', 'messages' => ['Reply'], 'questions' => [], 'choices' => [['label' => 'Open', 'target' => 'child']]],
    ]];
    expect(app(BlueprintNormalizer::class)->normalize($original))->toBe($original);
    expect(app(BlueprintCompiler::class)->validate($original))->toBe($original);
});

it('resolves repeated identical destinations within one screen without dropping a choice', function () {
    $original = mixedStudioBlueprint();
    $original['screens'][0]['choices'][] = $original['screens'][0]['choices'][0];
    $fixed = app(BlueprintCompiler::class)->validate(app(BlueprintNormalizer::class)->normalize($original));
    $choices = $fixed['screens'][0]['choices'];
    expect($choices)->toHaveCount(3);
    expect($choices[0]['label'])->not->toBe($choices[2]['label']);
    expect($choices[0]['target'])->toBe($choices[2]['target']);
});

it('handles UTF16 joined emoji shortening reserved triggers and generated key collisions deterministically', function () {
    $original = mixedStudioBlueprint();
    $original['screens'][0]['choices'][0]['label'] = '000';
    $original['screens'][0]['choices'][1]['label'] = str_repeat('👨‍👩‍👧‍👦', 4).' English language';
    $original['screens'][] = ['key' => 'NODE_03_menu', 'name' => 'Reserved name', 'messages' => ['Reserved message'], 'questions' => [],
        'choices' => [['label' => 'Reserve', 'target' => 'NODE_03_menu']]];
    $original['screens'][0]['choices'][] = ['label' => 'Reserve', 'target' => 'NODE_03_menu'];
    $fixed = app(BlueprintCompiler::class)->validate(app(BlueprintNormalizer::class)->normalize($original));
    expect($fixed['screens'][0]['choices'][0]['label'])->not->toBe('000');
    $byKey = array_column($fixed['screens'], null, 'key');
    expect($byKey['NODE_03']['next'])->toBe('NODE_03_menu_2');
    expect($byKey['NODE_03_menu']['messages'])->toBe(['Reserved message']);
    expect(mb_check_encoding(json_encode($fixed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'UTF-8'))->toBeTrue();
});

it('does not invent graph links remove unreachable screens or silently accept invalid links', function (string $case) {
    $data = mixedStudioBlueprint();
    match ($case) {
        'missing target' => $data['screens'][0]['choices'][0]['target'] = 'missing',
        'unreachable' => $data['screens'][] = ['key' => 'FALLBACK', 'name' => 'Fallback', 'messages' => ['Do not remove me'], 'questions' => [], 'choices' => []],
        'mailto' => $data['screens'][2]['cta']['url'] = 'mailto:contact@example.org',
        'unsafe url' => $data['screens'][2]['cta']['url'] = 'javascript:alert(1)',
        'conflicting next' => $data['screens'][2]['next'] = 'NODE_01',
        'missing body' => $data['screens'][2]['messages'] = [],
    };
    expect(fn () => app(BlueprintCompiler::class)->validate(app(BlueprintNormalizer::class)->normalize($data)))->toThrow(ValidationException::class);
})->with(['missing target', 'unreachable', 'mailto', 'unsafe url', 'conflicting next', 'missing body']);

it('normalizes direct generation without another provider request for label and CTA errors', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(normalizerResponse(mixedStudioBlueprint()))]);
    $result = app(BlueprintGenerator::class)->generate('Source document', 'Preserve all text');
    expect($result['issues'])->toContain(BlueprintNormalizer::REVIEW_ISSUE);
    expect($result['adjustments'])->not->toBeEmpty();
    app(BlueprintCompiler::class)->compile($result);
    Http::assertSentCount(1);
});

it('normalizes inventory labels restores their batch values and splits CTA screens only in assembly', function () {
    $original = mixedStudioBlueprint();
    $plan = $original;
    foreach ($plan['screens'] as &$screen) {
        unset($screen['messages'], $screen['questions'], $screen['cta']);
        $screen['source_hint'] = 'Exact source screen '.$screen['key'];
    }
    unset($screen);
    $sequence = Http::sequence()->push(normalizerResponse($plan));
    foreach (array_chunk($original['screens'], 3) as $screens) {
        $sequence->push(normalizerResponse(['screens' => $screens, 'issues' => []]));
    }
    Http::fake(['generativelanguage.googleapis.com/*' => $sequence]);
    $service = app(BlueprintRecovery::class);
    $plan = $service->plan('Original source', 'Preserve content', null);
    expect($plan['issues'])->toContain(BlueprintNormalizer::REVIEW_ISSUE);
    expect($plan['screens'])->toHaveCount(count($original['screens']));
    $completed = [];
    foreach (array_chunk(array_column($plan['screens'], 'key'), 3) as $keys) {
        $batch = $service->batch('Original source', 'Preserve content', null, $plan, $completed, $keys, 24000);
        $completed = array_merge($completed, $batch['screens']);
    }
    $final = $service->assemble($plan, $completed, true);
    expect(count($final['screens']))->toBeGreaterThan(count($original['screens']));
    expect(array_column($final['adjustments'], 'reason'))->toContain('split_cta');
    app(BlueprintCompiler::class)->compile($final);
    Http::assertSentCount(1 + (int) ceil(count($original['screens']) / 3));
});

it('still rejects batch changes to destinations instead of masking a changed graph', function () {
    $original = mixedStudioBlueprint();
    $plan = app(BlueprintNormalizer::class)->normalize($original);
    $changed = $original['screens'][0];
    $changed['choices'][0]['target'] = 'NODE_03';
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(normalizerResponse(['screens' => [$changed], 'issues' => []]))]);
    expect(fn () => app(BlueprintRecovery::class)->batch('Source', 'Build', null, $plan, [], ['NODE_LANG'], 24000))
        ->toThrow(ValidationException::class);
    Http::assertSentCount(2);
});

it('preserves question-only CTA continuations and all introductory messages', function () {
    $data = ['name' => 'Question and link', 'start' => 'START', 'trigger' => '000', 'issues' => [], 'screens' => [
        ['key' => 'START', 'name' => 'Start', 'messages' => ['Introduction', 'Open this link'],
            'questions' => [['text' => 'Your name?', 'variable' => 'name', 'input_type' => 'text', 'retry_message' => 'Please enter your name']],
            'choices' => [], 'cta' => ['label' => 'Website', 'url' => 'https://example.org/'], 'next' => 'END'],
        ['key' => 'END', 'name' => 'End', 'messages' => ['Done'], 'questions' => [], 'choices' => []],
    ]];
    $fixed = app(BlueprintCompiler::class)->validate(app(BlueprintNormalizer::class)->normalize($data));
    $screens = array_column($fixed['screens'], null, 'key');
    expect($screens['START']['messages'])->toBe(['Introduction']);
    expect($screens['START']['questions'])->toBe($data['screens'][0]['questions']);
    expect($screens['START']['next'])->toBe('START_link');
    expect($screens['START_link']['messages'])->toBe(['Open this link']);
    expect($screens['START_link']['cta'])->toBe($data['screens'][0]['cta']);
    expect($screens['START_link']['next'])->toBe('END');
    expect(app(BlueprintNormalizer::class)->normalize($fixed))->toBe($fixed);
});

it('stops safely when adding CTA steps would exceed the screen limit', function () {
    $template = mixedStudioBlueprint()['screens'][2];
    $data = ['name' => 'Large', 'start' => 'S0', 'trigger' => '000', 'issues' => [], 'screens' => []];
    for ($i = 0; $i < 101; $i++) {
        $data['screens'][] = array_merge($template, ['key' => 'S'.$i, 'choices' => [['label' => 'Next', 'target' => 'S'.(($i + 1) % 101)]]]);
    }
    expect(fn () => app(BlueprintNormalizer::class)->normalize($data))->toThrow(ValidationException::class);
});
