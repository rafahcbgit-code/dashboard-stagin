<?php

use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new RuntimeException('Chat template tests require an isolated SQLite in-memory database.');
    }

    // This focused schema avoids unrelated MySQL-only migrations on isolated SQLite.
    foreach (['templates', 'contacts', 'teams', 'organizations', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password');
        $table->string('role');
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('organizations', function (Blueprint $table) {
        $table->id();
        $table->string('uuid');
        $table->string('identifier');
        $table->string('name');
        $table->text('address')->nullable();
        $table->string('timezone')->nullable();
        $table->unsignedBigInteger('created_by');
        $table->text('metadata')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('teams', function (Blueprint $table) {
        $table->id();
        $table->string('uuid');
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role');
        $table->string('status');
        $table->unsignedBigInteger('created_by');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('contacts', function (Blueprint $table) {
        $table->id();
        $table->string('uuid');
        $table->unsignedBigInteger('organization_id');
        $table->string('phone');
        $table->string('first_name')->nullable();
        $table->string('last_name')->nullable();
        $table->string('email')->nullable();
        $table->boolean('is_subscribed')->default(true);
        $table->boolean('is_blocked')->default(false);
        $table->unsignedBigInteger('created_by');
        $table->softDeletes();
        $table->timestamps();
    });
    Schema::create('templates', function (Blueprint $table) {
        $table->id();
        $table->string('uuid');
        $table->unsignedBigInteger('organization_id');
        $table->string('meta_id');
        $table->string('name');
        $table->string('category');
        $table->string('language');
        $table->text('metadata');
        $table->string('status');
        $table->unsignedBigInteger('created_by');
        $table->softDeletes();
        $table->timestamps();
    });
});

function templateSendFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    $contact = Contact::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $owner->id,
    ]);

    return [$owner, $organization, $contact];
}

function createConversationTemplate(Organization $organization, User $owner, string $status = 'APPROVED'): Template
{
    return Template::create([
        'organization_id' => $organization->id,
        'meta_id' => 'test-' . uniqid(),
        'name' => 'chat_template_' . uniqid(),
        'category' => 'UTILITY',
        'language' => 'en_US',
        'metadata' => json_encode(['template_type' => 'carousel', 'carousel_cards' => []]),
        'status' => $status,
        'created_by' => $owner->id,
    ]);
}

it('rejects another organization conversation before either template send path', function () {
    [$owner, $organization] = templateSendFixture();
    [, , $foreignContact] = templateSendFixture();

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->post('/chat/' . $foreignContact->uuid . '/send/template', ['template' => 'unknown'])
        ->assertNotFound();

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->post('/chat/' . $foreignContact->uuid . '/send/carousel-template', ['template' => 'unknown'])
        ->assertNotFound();
});

it('keeps unapproved templates out of both conversation send paths', function () {
    [$owner, $organization, $contact] = templateSendFixture();
    $template = createConversationTemplate($organization, $owner, 'PENDING');

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->post('/chat/' . $contact->uuid . '/send/template', ['template' => (string) $template->uuid])
        ->assertRedirect()
        ->assertSessionHas('status.type', 'error');

    $this->withoutMiddleware()->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->post('/chat/' . $contact->uuid . '/send/carousel-template', ['template' => (string) $template->uuid])
        ->assertRedirect()
        ->assertSessionHas('status.type', 'error');
});

it('does not pass a foreign contact to the outbound template service', function () {
    [$owner, $organization] = templateSendFixture();
    [, , $foreignContact] = templateSendFixture();
    $template = createConversationTemplate($organization, $owner);
    $service = new ChatService($organization->id);

    $standard = $service->sendTemplateMessage((object) ['template' => $template->uuid], $foreignContact->uuid);
    $carousel = $service->sendCarouselTemplateMessage($foreignContact->uuid, $template->id);

    expect($standard->success)->toBeFalse()
        ->and($carousel->success)->toBeFalse();
});
