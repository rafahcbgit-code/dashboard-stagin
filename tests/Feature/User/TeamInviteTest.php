<?php

use App\Mail\CustomEmail;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTransactions::class);

function createTeamUserForInviteTest(string $role, Organization $organization, User $creator): User
{
    $user = User::factory()->create(['code_verified' => 1]);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => 'active',
        'created_by' => $creator->id,
    ]);

    return $user;
}

function seedInviteEmailSettings(User $updatedBy): void
{
    Setting::updateOrCreate(['key' => 'smtp_email_active'], ['value' => '1']);
    Setting::updateOrCreate(['key' => 'company_name'], ['value' => 'Chatberry']);

    EmailTemplate::create([
        'name' => 'Invite',
        'subject' => 'Invitation from {{FullName}}',
        'body' => 'Use this link: {{Link}}',
        'updated_by' => $updatedBy->id,
    ]);
}

function createActiveSubscriptionForInviteTest(Organization $organization): void
{
    Subscription::create([
        'organization_id' => $organization->id,
        'status' => 'active',
        'start_date' => now(),
        'valid_until' => now()->addMonth(),
    ]);
}

it('allows managers to invite team members and queues the invite email', function () {
    Mail::fake();

    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    createActiveSubscriptionForInviteTest($organization);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $manager = createTeamUserForInviteTest('manager', $organization, $owner);
    seedInviteEmailSettings($owner);

    $response = $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->post('/team/invite', [
            'email' => 'new-agent@example.com',
            'role' => 'agent',
        ]);

    $response->assertRedirect();

    expect(TeamInvite::where('email', 'new-agent@example.com')
        ->where('organization_id', $organization->id)
        ->where('invited_by', $manager->id)
        ->exists())->toBeTrue();

    Mail::assertQueued(CustomEmail::class);
});

it('allows managers to create team members with a password without sending an invite email', function () {
    Mail::fake();

    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    createActiveSubscriptionForInviteTest($organization);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $manager = createTeamUserForInviteTest('manager', $organization, $owner);

    $response = $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->post('/team/invite', [
            'email' => 'direct-agent@example.com',
            'role' => 'agent',
            'create_password' => true,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

    $response->assertRedirect();

    $user = User::where('email', 'direct-agent@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secure-password', $user->password))->toBeTrue()
        ->and($user->code_verified)->toBe(1)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Team::where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', 'agent')
            ->where('status', 'active')
            ->exists())->toBeTrue()
        ->and(TeamInvite::where('email', 'direct-agent@example.com')->exists())->toBeFalse();

    Mail::assertNothingQueued();
});

it('prevents agents from inviting team members directly', function () {
    Mail::fake();

    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    createActiveSubscriptionForInviteTest($organization);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $agent = createTeamUserForInviteTest('agent', $organization, $owner);
    seedInviteEmailSettings($owner);

    $response = $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($agent)
        ->withSession(['current_organization' => $organization->id])
        ->post('/team/invite', [
            'email' => 'blocked@example.com',
            'role' => 'agent',
        ]);

    $response->assertForbidden();

    expect(TeamInvite::where('email', 'blocked@example.com')->exists())->toBeFalse();
    Mail::assertNothingQueued();
});
