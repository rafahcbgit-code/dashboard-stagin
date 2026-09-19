<?php

use App\Jobs\GenerateChatbotStudioDraft;
use App\Models\ChatbotStudioDraft;
use App\Models\User;
use App\Services\ChatbotStudio\BlueprintCompiler;
use App\Services\ChatbotStudio\BlueprintGenerator;
use App\Services\ChatbotStudio\BlueprintPublisher;
use App\Services\ChatbotStudio\SourceReader;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\FlowBuilder\Services\FlowMetadataParser;
use Modules\FlowBuilder\Validators\FlowValidator;

uses(Tests\TestCase::class);

function studioAdmin(int $id = 1, string $role = 'admin'): User
{
    return (new User())->forceFill(['id' => $id, 'role' => $role]);
}
function studioBlueprint(int $choices = 2): array
{
    $menu = ['key' => 'welcome', 'name' => 'Welcome', 'messages' => ["Welcome\nChoose a service"], 'questions' => [], 'choices' => [], 'list_label' => 'Services', 'cta' => null, 'next' => null];
    $screens = [];
    for ($i = 0; $i < $choices; $i++) {
        $menu['choices'][] = ['label' => 'Service '.$i, 'target' => 'service_'.$i];
        $screens[] = ['key' => 'service_'.$i, 'name' => 'Service '.$i, 'messages' => ['Original answer '.$i], 'questions' => [], 'choices' => []];
    }
    return ['name' => 'Test bot', 'start' => 'welcome', 'trigger' => '000', 'issues' => [], 'screens' => [$menu, ...$screens]];
}

function studioDraft(array $overrides = []): ChatbotStudioDraft
{
    return ChatbotStudioDraft::create(array_merge(['id' => (string) Str::uuid(), 'admin_id' => 1, 'organization_id' => 285,
        'version' => 1, 'status' => 'draft', 'source' => 'Original source', 'blueprint' => studioBlueprint(), 'conversation' => []], $overrides));
}

function studioRecoveryPlan(array $blueprint): array
{
    $blueprint['screens'] = array_map(function ($screen) {
        return ['key' => $screen['key'], 'name' => $screen['name'], 'choices' => $screen['choices'],
            'next' => $screen['next'] ?? null, 'list_label' => $screen['list_label'] ?? null, 'source_hint' => 'Exact content for '.$screen['key']];
    }, $blueprint['screens']);
    return $blueprint;
}

function studioRecoveryResponse(array $data, string $reason = 'STOP'): array
{
    return ['candidates' => [['finishReason' => $reason, 'content' => ['parts' => [['text' => json_encode($data)]]]]]];
}

function studioMeteredResponse(array $data, string $reason = 'STOP'): array
{
    return array_merge(studioRecoveryResponse($data, $reason), ['usageMetadata' => [
        'promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 10,
        'cachedContentTokenCount' => 40, 'totalTokenCount' => 130,
    ]]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array', 'session.driver' => 'array']);
    config(['chatbot_studio.api_key' => 'test-only-key', 'chatbot_studio.free_tier_confirmed' => true]);
    DB::purge('sqlite');
    Schema::create('organizations', function (Blueprint $table) {
        $table->id(); $table->uuid('uuid')->unique(); $table->string('name'); $table->softDeletes();
    });
    Schema::create('flows', function (Blueprint $table) {
        $table->id(); $table->uuid('uuid')->unique(); $table->unsignedBigInteger('organization_id'); $table->string('name');
        $table->text('description')->nullable(); $table->text('metadata'); $table->string('status'); $table->boolean('is_builtin'); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('trigger_groups', function (Blueprint $table) {
        $table->id(); $table->uuid('uuid')->unique(); $table->unsignedBigInteger('organization_id'); $table->string('name');
        $table->string('type'); $table->text('keywords'); $table->string('match_mode'); $table->integer('ai_confidence');
        $table->unsignedBigInteger('flow_id'); $table->integer('priority'); $table->boolean('is_system'); $table->boolean('is_enabled');
        $table->string('rate_limit_type'); $table->text('metadata'); $table->timestamps(); $table->softDeletes();
    });
    Schema::create('roles', function (Blueprint $table) { $table->id(); $table->string('name'); $table->softDeletes(); });
    Schema::create('role_permissions', function (Blueprint $table) { $table->id(); $table->unsignedBigInteger('role_id'); $table->string('module'); $table->string('action'); });
    (require base_path('database/migrations/2026_09_12_180000_create_chatbot_studio_drafts_table.php'))->up();
    (require base_path('database/migrations/2026_09_16_180000_add_generation_state_to_chatbot_studio_drafts.php'))->up();
    (require base_path('database/migrations/2026_09_16_190000_add_token_usage_to_chatbot_studio_drafts.php'))->up();
    DB::table('organizations')->insert(['id' => 285, 'uuid' => (string) Str::uuid(), 'name' => 'Test organization']);
    $this->withoutMiddleware([App\Http\Middleware\CheckAppStatus::class, App\Http\Middleware\HandleInertiaRequests::class,
        App\Http\Middleware\SetOrganizationFromSession::class, App\Http\Middleware\Localization::class]);
    Queue::fake();
});

it('compiles every choice to a separate flow through a native portal', function (int $count) {
    $compiled = app(BlueprintCompiler::class)->compile(studioBlueprint($count));
    expect($compiled['flows'])->toHaveCount($count + 1);
    expect($compiled['triggers'])->toHaveCount($count + 1);
    $metadata = $compiled['flows']['welcome']['metadata'];
    $parser = new FlowMetadataParser(json_encode($metadata));
    expect($parser->getNodeById('choices')['type'])->toBe($count > 3 ? 'list' : 'buttons');
    for ($i = 0; $i < $count; $i++) {
        $reply = $count > 3 ? 'choice_'.$i : 'button'.($i + 1);
        expect($parser->getDirectPortalTargetUuid('choices', $reply))->toBe($compiled['flows']['service_'.$i]['uuid']);
    }
    $metadata['nodes'] = array_values(array_filter($metadata['nodes'], fn ($n) => $n['type'] !== 'portal'));
    expect(app(FlowValidator::class)->validateMessageNodes($metadata))->toBeTrue();
})->with([1, 3, 4, 10]);

it('accumulates actual usage for repair calls without double counting cached input', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']],
        'token_usage' => App\Services\ChatbotStudio\TokenUsageTracker::emptySummary()]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioMeteredResponse(['invalid' => true]))
        ->push(studioMeteredResponse(studioBlueprint()))]);
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle(app(BlueprintGenerator::class));
    $usage = $draft->fresh()->token_usage;
    expect($usage)->toMatchArray(['requests' => 2, 'reported_requests' => 2, 'total_tokens' => 260,
        'input_tokens' => 200, 'output_tokens' => 40, 'thinking_tokens' => 20, 'cached_input_tokens' => 80,
        'historical_usage_unknown' => false]);
    expect($draft->fresh()->status)->toBe('draft');
    foreach (Http::recorded() as [$request]) {
        expect(json_encode($request->data()))->not->toContain($draft->id, 'usage_draft_id');
    }
    $this->actingAs(studioAdmin(), 'admin')->getJson('/admin/chatbot-studio/drafts/'.$draft->id)
        ->assertOk()->assertJsonPath('token_usage.total_tokens', 260);
    $this->actingAs(studioAdmin(2), 'admin')->getJson('/admin/chatbot-studio/drafts/'.$draft->id)->assertNotFound();
});

it('retains metered truncation and plan usage across quota failure retry and publication', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']]]);
    $blueprint = studioBlueprint();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioMeteredResponse([], 'MAX_TOKENS'))
        ->push(studioMeteredResponse(studioRecoveryPlan($blueprint)))
        ->push(['error' => ['message' => 'quota']], 429)
        ->push(studioMeteredResponse(['screens' => $blueprint['screens'], 'issues' => []]))]);
    foreach ([0, 1, 2] as $step) {
        (new GenerateChatbotStudioDraft($draft->id, 1, $step))->handle(app(BlueprintGenerator::class));
    }
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->token_usage)->toMatchArray(['requests' => 3, 'reported_requests' => 2, 'total_tokens' => 260,
        'historical_usage_unknown' => true]);
    $this->actingAs(studioAdmin(), 'admin')->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 1])->assertStatus(202);
    (new GenerateChatbotStudioDraft($draft->id, 2, 2))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('draft');
    expect($draft->fresh()->token_usage)->toMatchArray(['requests' => 4, 'reported_requests' => 3, 'total_tokens' => 390]);
    (new GenerateChatbotStudioDraft($draft->id, 1, 2))->handle(app(BlueprintGenerator::class));
    Http::assertSentCount(4);
    app(BlueprintPublisher::class)->publish($draft->id, 1, 2);
    expect($draft->fresh()->token_usage['total_tokens'])->toBe(390);
});

it('records unknown usage for unavailable responses and connection failures without inventing tokens', function () {
    Illuminate\Support\Sleep::fake();
    $draft = studioDraft();
    $calls = 0;
    Http::fake(['generativelanguage.googleapis.com/*' => function () use (&$calls) {
        $calls++;
        if ($calls > 3) {
            throw new Illuminate\Http\Client\ConnectionException('private failure details');
        }
        return $calls < 3 ? Http::response([], 503) : Http::response(studioRecoveryResponse(studioBlueprint()));
    }]);
    $ai = app(App\Services\ChatbotStudio\StudioAiService::class);
    $ai->chat('system', 'source', ['usage_draft_id' => $draft->id]);
    expect($draft->fresh()->token_usage)->toMatchArray(['requests' => 3, 'reported_requests' => 0, 'total_tokens' => 0]);
    expect(fn () => $ai->chat('system', 'source', ['usage_draft_id' => $draft->id]))->toThrow(ValidationException::class);
    expect($draft->fresh()->token_usage)->toMatchArray(['requests' => 4, 'reported_requests' => 0]);
});

it('initializes new draft tracking and keeps usage through manual edits and revisions', function () {
    $this->actingAs(studioAdmin(), 'admin');
    $created = $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => 285, 'message' => 'Build'])
        ->assertStatus(202)->assertJsonPath('token_usage.requests', 0)->assertJsonPath('token_usage.historical_usage_unknown', false);
    $id = $created->json('id');
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioMeteredResponse(studioBlueprint()))]);
    (new GenerateChatbotStudioDraft($id, 1))->handle(app(BlueprintGenerator::class));
    $this->putJson('/admin/chatbot-studio/drafts/'.$id, ['version' => 1, 'blueprint' => studioBlueprint()])
        ->assertOk()->assertJsonPath('token_usage.total_tokens', 130);
    $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => 285, 'draft_id' => $id, 'version' => 2, 'message' => 'Revise'])
        ->assertStatus(202)->assertJsonPath('token_usage.total_tokens', 130);
    (new GenerateChatbotStudioDraft($id, 3))->handle(app(BlueprintGenerator::class));
    expect(ChatbotStudioDraft::find($id)->token_usage['total_tokens'])->toBe(260);
});

it('counts in-flight usage after cancellation without restoring stale generation state', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']]]);
    Http::fake(['generativelanguage.googleapis.com/*' => function () use ($draft) {
        $draft->update(['version' => 2, 'status' => 'failed']);
        return Http::response(studioMeteredResponse(studioBlueprint()));
    }]);
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->token_usage)->toMatchArray(['requests' => 1, 'total_tokens' => 130]);
});

it('preserves original messages and uses the engine question success handle', function () {
    $blueprint = studioBlueprint();
    $blueprint['screens'][1]['messages'] = ['النص الأصلي كما هو.'];
    $blueprint['screens'][1]['questions'] = [['text' => 'الاسم؟', 'variable' => 'customer_name', 'input_type' => 'text', 'retry_message' => 'حاول مجددًا']];
    $blueprint['screens'][1]['next'] = 'service_1';
    $flow = app(BlueprintCompiler::class)->compile($blueprint)['flows']['service_0'];
    $parser = new FlowMetadataParser(json_encode($flow['metadata']));
    expect($parser->getNodeFields('message_0')['body'])->toBe('النص الأصلي كما هو.');
    expect($parser->getNextNode('question_0', '', 'success|question_0')['type'])->toBe('portal');
});

it('rejects malformed or ambiguous flow graphs', function (string $case) {
    $data = studioBlueprint();
    match ($case) {
        'long button' => $data['screens'][0]['choices'][0]['label'] = str_repeat('😀', 11),
        'missing target' => $data['screens'][0]['choices'][0]['target'] = 'missing',
        'duplicate keyword' => $data['screens'][0]['choices'][1]['label'] = 'Service 0',
        'missing cta body' => $data['screens'][1] = ['key' => 'service_0', 'name' => 'CTA', 'messages' => [], 'questions' => [], 'choices' => [], 'cta' => ['label' => 'Website', 'url' => 'https://example.org'], 'next' => 'service_1'],
        'automatic loop' => $data['screens'][1]['next'] = 'service_0',
        'unreachable' => $data['screens'][] = ['key' => 'extra', 'name' => 'Extra', 'messages' => ['Unused'], 'questions' => [], 'choices' => []],
    };
    expect(fn () => app(BlueprintCompiler::class)->compile($data))->toThrow(ValidationException::class);
})->with(['long button', 'missing target', 'duplicate keyword', 'missing cta body', 'automatic loop', 'unreachable']);

it('publishes atomically with fresh IDs and all portal and trigger references', function () {
    $draft = studioDraft();
    $publisher = app(BlueprintPublisher::class);
    $result = $publisher->publish($draft->id, 1, 1);
    expect($result['flows'])->toBe(3)->and($result['triggers'])->toBe(3);
    foreach (DB::table('flows')->get() as $flow) {
        $metadata = json_decode($flow->metadata, true);
        foreach ($metadata['nodes'] as $node) {
            if ($node['type'] !== 'portal') continue;
            $fields = $node['data']['metadata']['fields'];
            $target = DB::table('flows')->where('id', $fields['target_flow_id'])->first();
            expect($target->uuid)->toBe($fields['target_flow_uuid'])->and($target->organization_id)->toBe(285);
            expect(DB::table('trigger_groups')->where('uuid', $fields['trigger_uuid'])->value('flow_id'))->toBe($target->id);
        }
    }
    expect($publisher->publish($draft->id, 1, 1))->toBe($result);
    expect(DB::table('flows')->count())->toBe(3);
    expect(DB::table('trigger_groups')->where('keywords', '["000"]')->value('flow_id'))->toBe($result['start_flow_id']);
    expect(DB::table('trigger_groups')->whereIn('type', ['greeting', 'new_contact', 'away'])->count())->toBe(0);
});

it('rejects existing triggers and unresolved requirements without any writes', function () {
    $publisher = app(BlueprintPublisher::class);
    $publisher->publish(studioDraft()->id, 1, 1);
    expect(fn () => $publisher->publish(studioDraft()->id, 1, 1))->toThrow(ValidationException::class);
    $blueprint = studioBlueprint(); $blueprint['issues'] = ['Booking API is not configured.'];
    expect(fn () => $publisher->publish(studioDraft(['blueprint' => $blueprint])->id, 1, 1))->toThrow(ValidationException::class);
    expect(DB::table('flows')->count())->toBe(3)->and(DB::table('trigger_groups')->count())->toBe(3);
});

it('requires review of deterministic flow adjustments before publishing native CTA and portal flows', function () {
    $source = require __DIR__.'/../Fixtures/ChatbotStudioMixedBlueprint.php';
    $fixed = app(App\Services\ChatbotStudio\BlueprintNormalizer::class)->normalize($source);
    $draft = studioDraft(['blueprint' => app(BlueprintCompiler::class)->validate($fixed)]);
    expect(fn () => app(BlueprintPublisher::class)->publish($draft->id, 1, 1))->toThrow(ValidationException::class);
    expect(DB::table('flows')->count())->toBe(0);
    $this->actingAs(studioAdmin(), 'admin')->getJson('/admin/chatbot-studio/drafts/'.$draft->id)
        ->assertOk()->assertJsonCount(count($fixed['adjustments']), 'blueprint.adjustments');
    $fixed['issues'] = [];
    $this->actingAs(studioAdmin(), 'admin')->putJson('/admin/chatbot-studio/drafts/'.$draft->id, ['version' => 1, 'blueprint' => $fixed])->assertOk();
    $result = app(BlueprintPublisher::class)->publish($draft->id, 1, 2);
    expect($result['flows'])->toBe(count($fixed['screens']));
    expect($draft->fresh()->blueprint['adjustments'])->toBe($fixed['adjustments']);
    foreach (DB::table('flows')->get() as $flow) {
        $metadata = json_decode($flow->metadata, true);
        $messages = $metadata;
        $messages['nodes'] = array_values(array_filter($messages['nodes'], fn ($node) => $node['type'] !== 'portal'));
        expect(app(FlowValidator::class)->validateMessageNodes($messages))->toBeTrue();
        foreach ($metadata['nodes'] as $node) {
            if ($node['type'] === 'portal') {
                $fields = $node['data']['metadata']['fields'];
                $target = DB::table('flows')->where('id', $fields['target_flow_id'])->first();
                expect($target->organization_id)->toBe(285)->and($target->uuid)->toBe($fields['target_flow_uuid']);
            }
        }
    }
});

it('rolls back all flows if trigger insertion fails', function () {
    DB::statement("CREATE TRIGGER fail_studio BEFORE INSERT ON trigger_groups BEGIN SELECT RAISE(ABORT, 'forced failure'); END");
    $draft = studioDraft();
    expect(fn () => app(BlueprintPublisher::class)->publish($draft->id, 1, 1))->toThrow(Illuminate\Database\QueryException::class);
    expect(DB::table('flows')->count())->toBe(0)->and($draft->fresh()->status)->toBe('draft');
});

it('requires admin authentication and edit permission', function () {
    $this->getJson('/admin/chatbot-studio/organizations')->assertUnauthorized();
    $user = studioAdmin(4, 'Viewer');
    DB::table('roles')->insert(['id' => 1, 'name' => 'Viewer']);
    DB::table('role_permissions')->insert(['role_id' => 1, 'module' => 'organizations', 'action' => 'view']);
    $this->actingAs($user, 'admin')->getJson('/admin/chatbot-studio/organizations')->assertOk();
    $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => 285, 'message' => 'Hello'])->assertForbidden();
});

it('accepts text, queues generation, preserves ownership and rejects stale revisions', function () {
    $this->actingAs(studioAdmin(), 'admin');
    $response = $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => '285', 'message' => 'My chatbot content'])->assertStatus(202);
    $id = $response->json('id');
    expect($response->json())->not->toHaveKey('source');
    Queue::assertPushed(GenerateChatbotStudioDraft::class, fn ($job) => $job->draftId === $id);
    $draft = ChatbotStudioDraft::findOrFail($id);
    $draft->update(['status' => 'draft', 'blueprint' => studioBlueprint()]);
    $this->putJson('/admin/chatbot-studio/drafts/'.$id, ['version' => 1, 'blueprint' => studioBlueprint(4)])->assertOk()->assertJsonPath('version', 2);
    expect($draft->fresh()->blueprint['screens'])->toHaveCount(5);
    $this->putJson('/admin/chatbot-studio/drafts/'.$id, ['version' => 1, 'blueprint' => studioBlueprint()])->assertStatus(409);
    $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => '285', 'draft_id' => $id, 'version' => '2', 'message' => 'Add Arabic'])->assertStatus(202);
    $this->actingAs(studioAdmin(2), 'admin')->getJson('/admin/chatbot-studio/drafts/'.$id)->assertNotFound();
    $this->postJson('/admin/chatbot-studio/drafts/'.$id.'/publish', ['version' => 3])->assertNotFound();
});

it('generates a draft and ignores stale background results after cancellation', function () {
    $draft = studioDraft(['status' => 'generating', 'blueprint' => null, 'conversation' => [['role' => 'user', 'content' => 'Build it']]]);
    $generator = Mockery::mock(BlueprintGenerator::class);
    $generator->shouldReceive('generate')->once()->andReturn(studioBlueprint());
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle($generator);
    expect($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->blueprint['start'])->toBe('welcome');
    $draft->refresh()->update(['status' => 'generating', 'version' => 2]);
    $this->actingAs(studioAdmin(), 'admin')
        ->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/cancel', ['version' => 2])->assertOk()->assertJsonPath('version', 3);
    (new GenerateChatbotStudioDraft($draft->id, 2))->handle($generator);
    expect($draft->fresh()->status)->toBe('draft');
});

it('keeps previous blueprint and actionable errors on generation failure', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Revision']]]);
    $generator = Mockery::mock(BlueprintGenerator::class);
    $generator->shouldReceive('generate')->andThrow(ValidationException::withMessages(['generation' => ['AI is unavailable.']]));
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle($generator);
    expect($draft->fresh()->status)->toBe('failed')->and($draft->fresh()->blueprint)->toBe(studioBlueprint());
    expect($draft->fresh()->errors['generation'])->toBe(['AI is unavailable.']);
});

it('reads simulator HTML as inert data and rejects unsafe URLs', function () {
    Http::preventStrayRequests();
    Http::fake(['https://chatbot.chatberry.click/demo' => Http::response('<iframe srcdoc="&lt;script&gt;const hiddenBranch=1;&lt;/script&gt;"></iframe>')]);
    $reader = app(SourceReader::class);
    expect($reader->fromUrl('https://chatbot.chatberry.click/demo'))->toContain('hiddenBranch');
    foreach (['http://127.0.0.1/test', 'https://chatbot.chatberry.click@127.0.0.1/test', 'https://chatbot.chatberry.click/demo?url=internal'] as $url) {
        expect(fn () => $reader->fromUrl($url))->toThrow(ValidationException::class);
    }
    expect($reader->fromFile(UploadedFile::fake()->createWithContent('flow.txt', 'Exact source text')))->toBe('Exact source text');
    expect(fn () => $reader->fromFile(UploadedFile::fake()->createWithContent('flow.exe', 'x')))->toThrow(ValidationException::class);
});

it('accepts a file or simulator link without a chat message', function () {
    $this->actingAs(studioAdmin(), 'admin');
    Http::fake(['https://chatbot.chatberry.click/demo' => Http::response('<html><body>Source branch</body></html>')]);
    $this->postJson('/admin/chatbot-studio/generate', ['organization_id' => 285, 'url' => 'https://chatbot.chatberry.click/demo'])
        ->assertStatus(202)->assertJsonPath('sources.0.type', 'url');
    $this->post('/admin/chatbot-studio/generate', ['organization_id' => 285, 'file' => UploadedFile::fake()->createWithContent('bot.txt', 'Create a welcome menu.')], ['Accept' => 'application/json'])
        ->assertStatus(202)->assertJsonPath('sources.0.name', 'bot.txt');
});

it('does not enqueue or create drafts when free cloud configuration is missing', function () {
    config(['chatbot_studio.api_key' => '']);
    $this->actingAs(studioAdmin(), 'admin')->postJson('/admin/chatbot-studio/generate', ['organization_id' => 285, 'message' => 'Hello'])
        ->assertUnprocessable();
    expect(ChatbotStudioDraft::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('recovers a truncated blueprint in checkpointed batches without publishing partial content', function () {
    $blueprint = studioBlueprint(4);
    $draft = studioDraft(['status' => 'generating', 'blueprint' => null, 'conversation' => [['role' => 'user', 'content' => 'Build every branch']]]);
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioRecoveryResponse([], 'MAX_TOKENS'))
        ->push(studioRecoveryResponse(studioRecoveryPlan($blueprint)))
        ->push(studioRecoveryResponse(['screens' => array_slice($blueprint['screens'], 0, 3), 'issues' => []]))
        ->push(studioRecoveryResponse(['screens' => array_slice($blueprint['screens'], 3), 'issues' => []]))]);
    $generator = app(BlueprintGenerator::class);
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle($generator);
    expect($draft->fresh()->generation_state['stage'])->toBe('plan');
    (new GenerateChatbotStudioDraft($draft->id, 1, 1))->handle($generator);
    (new GenerateChatbotStudioDraft($draft->id, 1, 2))->handle($generator);
    expect($draft->fresh()->blueprint)->toBeNull();
    expect($draft->fresh()->generation_state['completed'])->toHaveCount(3);
    expect($draft->fresh()->toArray())->not->toHaveKey('generation_state');
    (new GenerateChatbotStudioDraft($draft->id, 1, 2))->handle($generator);
    Http::assertSentCount(3);
    (new GenerateChatbotStudioDraft($draft->id, 1, 3))->handle($generator);
    expect($draft->fresh()->status)->toBe('draft');
    expect($draft->fresh()->generation_state)->toBeNull();
    expect($draft->fresh()->blueprint)->toBe(app(BlueprintCompiler::class)->validate($blueprint));
    expect(DB::table('flows')->count())->toBe(0);
    Queue::assertPushed(GenerateChatbotStudioDraft::class, 3);
});

it('automatically splits overflowing batches and preserves every exact screen', function () {
    $blueprint = studioBlueprint(4);
    $draft = studioDraft(['source' => str_repeat('x', 60001), 'status' => 'generating', 'blueprint' => null,
        'conversation' => [['role' => 'user', 'content' => 'Build every branch']]]);
    Http::preventStrayRequests();
    Http::fake(['generativelanguage.googleapis.com/*' => function ($request) use ($blueprint) {
        $input = json_decode($request['contents'][0]['parts'][0]['text'], true);
        if (!isset($input['requested_keys'])) {
            return Http::response(studioRecoveryResponse(studioRecoveryPlan($blueprint)));
        }
        if (count($input['requested_keys']) > 1) {
            return Http::response(studioRecoveryResponse([], 'MAX_TOKENS'));
        }
        $screen = collect($blueprint['screens'])->firstWhere('key', $input['requested_keys'][0]);
        return Http::response(studioRecoveryResponse(['screens' => [$screen], 'issues' => []]));
    }]);
    for ($i = 0; $i < 20 && $draft->fresh()->status === 'generating'; $i++) {
        (new GenerateChatbotStudioDraft($draft->id, 1, $draft->fresh()->generation_state['step'] ?? 0))->handle(app(BlueprintGenerator::class));
    }
    expect($draft->fresh()->status)->toBe('draft');
    expect($draft->fresh()->blueprint)->toBe(app(BlueprintCompiler::class)->validate($blueprint));
    expect(DB::table('flows')->count())->toBe(0);
});

it('blocks missing batch screens and resumes the saved stage with an owned versioned retry', function () {
    $blueprint = studioBlueprint(2);
    $state = ['stage' => 'batch', 'step' => 5, 'model' => config('chatbot_studio.model'), 'limit' => 24000,
        'plan' => studioRecoveryPlan($blueprint), 'completed' => [$blueprint['screens'][0]], 'pending' => [['service_0', 'service_1']]];
    $draft = studioDraft(['status' => 'generating', 'generation_state' => $state, 'conversation' => [['role' => 'user', 'content' => 'Exact original instructions']]]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(studioRecoveryResponse(['screens' => [$blueprint['screens'][1]], 'issues' => []]))
        ->push(studioRecoveryResponse(['screens' => [$blueprint['screens'][1]], 'issues' => []]))
        ->push(studioRecoveryResponse(['screens' => array_slice($blueprint['screens'], 1), 'issues' => []]))]);
    (new GenerateChatbotStudioDraft($draft->id, 1, 5))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->generation_state)->toBe($state);
    expect($draft->fresh()->blueprint)->toBe(studioBlueprint());
    $this->actingAs(studioAdmin(2), 'admin')->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 1])->assertNotFound();
    $this->actingAs(studioAdmin(), 'admin')->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 9])->assertConflict();
    $this->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 1])->assertStatus(202)->assertJsonMissingPath('generation_state');
    Queue::assertPushed(GenerateChatbotStudioDraft::class, fn ($job) => $job->version === 2 && $job->step === 5);
    $this->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 1])->assertConflict();
    (new GenerateChatbotStudioDraft($draft->id, 2, 5))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('draft');
    Http::assertSent(fn ($request) => json_decode($request['contents'][0]['parts'][0]['text'], true)['requested_keys'] === ['service_0', 'service_1']);
});

it('does not continue cancelled recovery or let stale failure callbacks overwrite progress', function () {
    $draft = studioDraft(['status' => 'generating', 'generation_state' => ['stage' => 'plan', 'step' => 2, 'limit' => 24000]]);
    $old = new GenerateChatbotStudioDraft($draft->id, 1, 1);
    $old->failed(new RuntimeException('old task'));
    expect($draft->fresh()->status)->toBe('generating');
    $this->actingAs(studioAdmin(), 'admin')->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/cancel', ['version' => 1])->assertOk();
    Http::fake();
    (new GenerateChatbotStudioDraft($draft->id, 1, 2))->handle(app(BlueprintGenerator::class));
    Http::assertNothingSent();
    expect($draft->fresh()->status)->toBe('draft');
});

it('stops safety blocked recovery without retrying or publishing', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']]]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioRecoveryResponse([], 'SAFETY'))]);
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->errors['generation'][0])->toContain('SAFETY');
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
});

it('requires edit permission for retry and keeps generation checkpoints on quota errors', function () {
    DB::table('roles')->insert(['id' => 7, 'name' => 'viewer']);
    DB::table('role_permissions')->insert(['role_id' => 7, 'module' => 'organizations', 'action' => 'view']);
    $draft = studioDraft(['status' => 'failed', 'conversation' => [['role' => 'user', 'content' => 'Build']],
        'generation_state' => ['stage' => 'plan', 'step' => 1, 'limit' => 24000]]);
    $this->actingAs(studioAdmin(1, 'viewer'), 'admin')->postJson('/admin/chatbot-studio/drafts/'.$draft->id.'/retry', ['version' => 1])->assertForbidden();
    $draft->update(['status' => 'generating']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 429)]);
    (new GenerateChatbotStudioDraft($draft->id, 1, 1))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->generation_state['stage'])->toBe('plan');
    Queue::assertNothingPushed();
});

it('increases the single-screen output budget once and then stops without losing checkpoints', function () {
    $blueprint = studioBlueprint(1);
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']],
        'generation_state' => ['stage' => 'batch', 'step' => 1, 'model' => config('chatbot_studio.model'), 'limit' => 24000,
            'plan' => studioRecoveryPlan($blueprint), 'completed' => [$blueprint['screens'][0]], 'pending' => [['service_0']]]]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(studioRecoveryResponse([], 'MAX_TOKENS'))]);
    (new GenerateChatbotStudioDraft($draft->id, 1, 1))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->generation_state['limit'])->toBe(65536);
    (new GenerateChatbotStudioDraft($draft->id, 1, 2))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->generation_state['completed'])->toHaveCount(1);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['generationConfig']['maxOutputTokens'] === 65536);
    Queue::assertPushed(GenerateChatbotStudioDraft::class, 1);
});

it('recovers empty output only once and starts fresh for explicit source revisions', function () {
    $draft = studioDraft(['status' => 'generating', 'conversation' => [['role' => 'user', 'content' => 'Build']]]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => []]]]])]);
    (new GenerateChatbotStudioDraft($draft->id, 1))->handle(app(BlueprintGenerator::class));
    (new GenerateChatbotStudioDraft($draft->id, 1, 1))->handle(app(BlueprintGenerator::class));
    expect($draft->fresh()->status)->toBe('failed');
    expect($draft->fresh()->errors['generation'][0])->toContain('EMPTY_OUTPUT');
    Queue::assertPushed(GenerateChatbotStudioDraft::class, 1);
    $this->actingAs(studioAdmin(), 'admin')->postJson('/admin/chatbot-studio/generate', [
        'organization_id' => 285, 'draft_id' => $draft->id, 'version' => 1, 'message' => 'New revision',
    ])->assertStatus(202);
    expect($draft->fresh()->generation_state)->toBeNull();
});
