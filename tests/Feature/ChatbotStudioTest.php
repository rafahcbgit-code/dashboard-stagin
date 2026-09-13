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

beforeEach(function () {
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
