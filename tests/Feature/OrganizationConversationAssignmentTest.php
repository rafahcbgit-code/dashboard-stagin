<?php

use App\Models\{ChatTicket, Contact, Organization, OrganizationApiKey, Team, User};
use App\Services\ApiNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->mock(ApiNotificationService::class)->shouldReceive('sendAssignmentNotification')->zeroOrMoreTimes();
    $this->agent = User::factory()->create();
    $this->org = Organization::factory()->create(['created_by' => $this->agent->id]);
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->agent->id]);
    Team::create(['organization_id' => $this->org->id, 'user_id' => $this->agent->id, 'role' => 'agent', 'status' => 'active', 'created_by' => $this->agent->id]);
    OrganizationApiKey::create(['organization_id' => $this->org->id, 'token' => 'assignment-test-key']);
    $this->endpoint = '/api/chats/'.$this->contact->uuid.'/assign';
});

it('requires an organization API key', function () {
    $this->putJson($this->endpoint, ['user_id' => $this->agent->id])->assertUnauthorized();
    $this->withToken('invalid')->getJson('/api/agents')->assertUnauthorized();
});

it('assigns without a mobile login and explicitly unassigns', function () {
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => $this->agent->id])
        ->assertOk()->assertJsonPath('data.assigned_to', $this->agent->id);
    $this->assertDatabaseHas('chat_tickets', ['contact_id' => $this->contact->id, 'assigned_to' => $this->agent->id, 'organization_id' => $this->org->id]);
    $this->assertDatabaseHas('chat_ticket_logs', ['contact_id' => $this->contact->id]);
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => null])->assertOk()->assertJsonPath('data.assigned_to', null);
    $this->assertDatabaseHas('chat_tickets', ['contact_id' => $this->contact->id, 'assigned_to' => null]);
});

it('rejects missing and invalid user ids', function () {
    foreach ([[], ['user_id' => 0], ['user_id' => 'bad']] as $body) {
        $this->withToken('assignment-test-key')->putJson($this->endpoint, $body)->assertUnprocessable();
    }
    expect(ChatTicket::where('contact_id', $this->contact->id)->exists())->toBeFalse();
});

it('scopes contacts and employees to the key even when organization is supplied by the caller', function () {
    $outsider = User::factory()->create();
    $otherOrg = Organization::factory()->create(['created_by' => $outsider->id]);
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id, 'created_by' => $outsider->id]);
    $this->withToken('assignment-test-key')->putJson('/api/chats/'.$otherContact->uuid.'/assign', ['user_id' => $this->agent->id, 'organization' => $otherOrg->id])->assertNotFound();
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => $outsider->id])->assertUnprocessable();
    $this->withToken('assignment-test-key')->getJson('/api/agents')->assertOk()->assertJsonCount(1, 'data.agents')->assertJsonPath('data.agents.0.id', $this->agent->id);
});

it('rejects inactive and removed employees and revoked keys', function () {
    Team::where('user_id', $this->agent->id)->update(['status' => 'inactive']);
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => $this->agent->id])->assertUnprocessable();
    Team::where('user_id', $this->agent->id)->update(['status' => 'active', 'deleted_at' => now()]);
    $this->withToken('assignment-test-key')->getJson('/api/agents')->assertJsonCount(0, 'data.agents');
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => $this->agent->id])->assertUnprocessable();
    OrganizationApiKey::where('token', 'assignment-test-key')->update(['deleted_at' => now()]);
    $this->withToken('assignment-test-key')->putJson($this->endpoint, ['user_id' => null])->assertUnauthorized();
});

