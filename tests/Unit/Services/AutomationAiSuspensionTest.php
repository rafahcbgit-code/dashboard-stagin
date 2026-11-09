<?php

use App\Http\Middleware\EnsureAutomationAiAvailable;
use App\Services\AiEscalationInstructionService;
use App\Services\AiFallbackService;
use App\Services\AiIntentClassifier;
use App\Services\AiKnowledgeBaseService;
use App\Services\AiService;
use App\Services\AiSettingsService;
use App\Services\AutomationSimulatorService;
use App\Services\BotSettingsService;
use App\Services\TriggerEvaluationService;
use App\Services\WorkingHoursService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);

beforeEach(function () {
    config(['ai.suspended' => true]);
    Http::preventStrayRequests();
});

test('suspension prevents model requests and preserves literal keyword triggers including zero', function () {
    $ai = new AiService();
    expect($ai->chat('system', 'question')->success)->toBeFalse();

    $classifier = new AiIntentClassifier($ai);
    expect($classifier->classify('0', ['0'], 'ai')->matchedKeyword)->toBe('0')
        ->and($classifier->classify(' HELP ', ['help'], 'ai')->matchMode)->toBe('exact')
        ->and($classifier->classify('similar intent', ['help'], 'ai'))->toBeNull();
    Http::assertNothingSent();
});

test('suspension stops knowledge lookup escalation and fallback before dependencies are accessed', function () {
    expect((new AiKnowledgeBaseService())->getEnabledForContext(123))->toBe('');
    $escalation = (new ReflectionClass(AiEscalationInstructionService::class))->newInstanceWithoutConstructor();
    $fallback = (new ReflectionClass(AiFallbackService::class))->newInstanceWithoutConstructor();
    expect($escalation->evaluate(123, 'help'))->toBeNull()
        ->and($fallback->attemptAnswer(123, 'help', null, ['ai_enabled' => true, 'ai_fallback_enabled' => true]))->toBeNull();
    Http::assertNothingSent();
});

test('suspended ai only settings cannot bypass normal bot processing or overwrite saved settings', function () {
    $settings = new AiSettingsService();
    expect($settings->isEnabled(123))->toBeFalse()
        ->and($settings->isAiOnlyMode(123))->toBeFalse()
        ->and($settings->update(123, ['ai_enabled' => true, 'ai_only_mode' => true]))->toBe(AiSettingsService::DEFAULTS);
});

test('existing ai action succeeds without external work so the flow can continue', function () {
    $service = (new ReflectionClass(ActionExecutionService::class))->newInstanceWithoutConstructor();
    expect($service->executeAction('ai_response', [], (object) ['id' => 123], 'question'))->toBeTrue();
    Http::assertNothingSent();
});

test('ai simulator cannot be reenabled by overrides', function () {
    $simulator = (new ReflectionClass(AutomationSimulatorService::class))->newInstanceWithoutConstructor();
    $result = $simulator->simulateAiResponse(123, 'question', ['ai_enabled' => true, 'ai_fallback_enabled' => true]);
    expect($result['success'])->toBeFalse()->and($result['ai_enabled'])->toBeFalse();
    Http::assertNothingSent();
});

test('bot flags and trigger simulator overrides stay disabled during suspension', function () {
    $settings = new BotSettingsService();
    foreach (['ai_fallback_enabled', 'ai_followup_enabled', 'ai_escalation_enabled'] as $key) {
        expect($settings->getSetting(123, $key, true))->toBeFalse();
    }

    $bot = Mockery::mock(BotSettingsService::class);
    $bot->shouldReceive('getSetting')->andReturn(30);
    $bot->shouldReceive('isAutoEscalationEnabled')->andReturnTrue();
    $simulator = new AutomationSimulatorService(
        Mockery::mock(TriggerEvaluationService::class),
        Mockery::mock(WorkingHoursService::class),
        $bot,
        new AiSettingsService(),
        Mockery::mock(AiFallbackService::class),
        Mockery::mock(AiEscalationInstructionService::class),
    );
    $result = $simulator->simulateTriggerEvaluation(123, 'question', false, true, [
        'automation_enabled' => false, 'ai_enabled' => true, 'ai_only_mode' => true,
        'ai_fallback_enabled' => true, 'ai_escalation_enabled' => true, 'ai_followup_enabled' => true,
    ]);
    foreach (['ai_enabled', 'ai_only_mode', 'ai_fallback_enabled', 'ai_escalation_enabled', 'ai_followup_enabled'] as $key) {
        expect($result['bot_settings'][$key])->toBeFalse();
    }
    expect($result['bot_settings']['auto_escalation_enabled'])->toBeTrue();
});

test('knowledge and ai administration middleware blocks direct requests and is reversible', function () {
    $middleware = new EnsureAutomationAiAvailable();
    try {
        $middleware->handle(Request::create('/automation/knowledge-base'), fn () => response('unexpected'));
        $this->fail('Suspended endpoint was accessible.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(503);
    }
    config(['ai.suspended' => false]);
    expect($middleware->handle(Request::create('/automation/knowledge-base'), fn () => response('available'))->getContent())->toBe('available');
});
