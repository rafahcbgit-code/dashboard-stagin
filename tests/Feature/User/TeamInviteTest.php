<?php

use App\Http\Middleware\VerifyCsrfToken;
use App\Mail\CustomEmail;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvite;
use App\Models\User;
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
            'phone' => '+966500000001',
            'role' => 'agent',
        ]);

    $response->assertRedirect();

    expect(TeamInvite::where('email', 'new-agent@example.com')
        ->where('organization_id', $organization->id)
        ->where('invited_by', $manager->id)
        ->where('phone', '+966500000001')
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
            'phone' => '+966 50 000 0002',
            'role' => 'agent',
            'create_password' => true,
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

    $response->assertRedirect();

    $user = User::where('email', 'direct-agent@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Hash::check('secure-password', $user->password))->toBeTrue()
        ->and($user->phone)->toBe('+966500000002')
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
            'phone' => '+966500000003',
            'role' => 'agent',
        ]);

    $response->assertForbidden();

    expect(TeamInvite::where('email', 'blocked@example.com')->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('requires a phone number when inviting a team member', function () {
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

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->from('/team')
        ->post('/team/invite', [
            'email' => 'missing-phone@example.com',
            'role' => 'agent',
        ])
        ->assertRedirect('/team')
        ->assertSessionHasErrors('phone');
});

it('allows managers to update a team member phone number without changing their role', function () {
    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);

    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $manager = createTeamUserForInviteTest('manager', $organization, $owner);
    $member = createTeamUserForInviteTest('agent', $organization, $owner);
    $membership = Team::where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$membership->uuid, [
            'phone' => '+966500000004',
            'role' => 'manager',
        ])
        ->assertRedirect();

    expect($member->fresh()->phone)->toBe('+966500000004')
        ->and($membership->fresh()->role)->toBe('agent');
});

it('allows owners to update both a team member phone number and role', function () {
    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    $ownerMembership = Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    $member = createTeamUserForInviteTest('agent', $organization, $owner);
    $membership = Team::where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$membership->uuid, [
            'phone' => '+966 50 000 0005',
            'role' => 'manager',
        ])
        ->assertRedirect();

    expect($member->fresh()->phone)->toBe('+966500000005')
        ->and($membership->fresh()->role)->toBe('manager')
        ->and($ownerMembership->fresh()->role)->toBe('owner');
});

it('allows a manager to update a phone number without submitting a role', function () {
    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    $manager = createTeamUserForInviteTest('manager', $organization, $owner);
    $member = createTeamUserForInviteTest('agent', $organization, $owner);
    $membership = Team::where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$membership->uuid, [
            'phone' => '+966500000006',
        ])
        ->assertRedirect();

    expect($member->fresh()->phone)->toBe('+966500000006')
        ->and($membership->fresh()->role)->toBe('agent');
});

it('rejects invalid or missing phone numbers when adding or updating a team member', function () {
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
    $member = createTeamUserForInviteTest('agent', $organization, $owner);
    $membership = Team::where('organization_id', $organization->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($owner)
        ->withSession(['current_organization' => $organization->id])
        ->from('/team')
        ->post('/team/invite', [
            'email' => 'invalid-phone@example.com',
            'phone' => '+123',
            'role' => 'agent',
        ])
        ->assertRedirect('/team')
        ->assertSessionHasErrors('phone');

    $this
        ->from('/team')
        ->put('/team/'.$membership->uuid, [
            'phone' => '',
        ])
        ->assertRedirect('/team')
        ->assertSessionHasErrors('phone');
});

it('prevents agents, owner-record edits, and cross-organization edits', function () {
    $owner = User::factory()->create(['code_verified' => 1]);
    $organization = Organization::factory()->create(['created_by' => $owner->id]);
    $ownerMembership = Team::create([
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    $manager = createTeamUserForInviteTest('manager', $organization, $owner);
    $agent = createTeamUserForInviteTest('agent', $organization, $owner);

    $otherOrganization = Organization::factory()->create(['created_by' => $owner->id]);
    $otherMember = User::factory()->create(['code_verified' => 1]);
    $otherMembership = Team::create([
        'organization_id' => $otherOrganization->id,
        'user_id' => $otherMember->id,
        'role' => 'agent',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($agent)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$ownerMembership->uuid, [
            'phone' => '+966500000007',
        ])
        ->assertForbidden();

    $this
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$ownerMembership->uuid, [
            'phone' => '+966500000008',
        ])
        ->assertForbidden();

    $this
        ->actingAs($manager)
        ->withSession(['current_organization' => $organization->id])
        ->put('/team/'.$otherMembership->uuid, [
            'phone' => '+966500000009',
        ])
        ->assertForbidden();
});

it('keeps invitations separate per organization and stores the phone after acceptance', function () {
    Mail::fake();

    $owner = User::factory()->create(['code_verified' => 1]);
    $firstOrganization = Organization::factory()->create(['created_by' => $owner->id]);
    $secondOrganization = Organization::factory()->create(['created_by' => $owner->id]);
    createActiveSubscriptionForInviteTest($firstOrganization);
    createActiveSubscriptionForInviteTest($secondOrganization);
    Team::create([
        'organization_id' => $firstOrganization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);
    Team::create([
        'organization_id' => $secondOrganization->id,
        'user_id' => $owner->id,
        'role' => 'owner',
        'status' => 'active',
        'created_by' => $owner->id,
    ]);

    $this
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->actingAs($owner)
        ->withSession(['current_organization' => $firstOrganization->id])
        ->post('/team/invite', [
            'email' => 'shared-email@example.com',
            'phone' => '+966500000010',
            'role' => 'agent',
        ])
        ->assertRedirect();

    $this
        ->actingAs($owner)
        ->withSession(['current_organization' => $secondOrganization->id])
        ->post('/team/invite', [
            'email' => 'shared-email@example.com',
            'phone' => '+966500000011',
            'role' => 'manager',
        ])
        ->assertRedirect();

    expect(TeamInvite::where('email', 'shared-email@example.com')->count())->toBe(2);

    $invite = TeamInvite::where('organization_id', $firstOrganization->id)
        ->where('email', 'shared-email@example.com')
        ->firstOrFail();
    $existingUser = User::factory()->create([
        'email' => 'shared-email@example.com',
        'phone' => '+15550000000',
        'code_verified' => 1,
    ]);

    $this
        ->post('/invite/'.$invite->code, [
            'first_name' => $existingUser->first_name,
            'last_name' => $existingUser->last_name,
            'email' => $existingUser->email,
        ])
        ->assertRedirect('/dashboard');

    expect($existingUser->fresh()->phone)->toBe('+966500000010')
        ->and(Team::where('organization_id', $firstOrganization->id)
            ->where('user_id', $existingUser->id)
            ->where('role', 'agent')
            ->exists())->toBeTrue()
        ->and(TeamInvite::whereKey($invite->id)->exists())->toBeFalse()
        ->and(TeamInvite::where('organization_id', $secondOrganization->id)
            ->where('email', 'shared-email@example.com')
            ->exists())->toBeTrue();
});
