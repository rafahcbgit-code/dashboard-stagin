<?php

/**
 * WebhookFailureAdminTest - Story 1.5 Feature Tests
 *
 * Tests for WebhookFailure admin endpoints:
 * - AC-1.5.4: Admin can view failed webhooks at /admin/webhook-failures
 * - AC-1.5.5: Admin can manually retry failed webhooks via POST /admin/webhook-failures/{id}/retry
 *
 * @see docs/sprint-artifacts/1-5-implement-webhook-retry-and-failure-handling.md
 */

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Organization;
use App\Models\UserAdmin;
use App\Models\WebhookFailure;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([ProcessWebhookJob::class]);
});

/**
 * Helper to create an admin user for testing.
 */
function createAdminUser(): UserAdmin
{
    return UserAdmin::create([
        'first_name' => 'Test',
        'last_name' => 'Admin',
        'email' => 'admin_'.uniqid().'@test.com',
        'password' => bcrypt('password'),
        'status' => 'active',
    ]);
}

/**
 * Helper to create a webhook failure record.
 */
function createWebhookFailure(int $organizationId, array $overrides = []): WebhookFailure
{
    return WebhookFailure::create(array_merge([
        'organization_id' => $organizationId,
        'job_type' => 'ProcessWebhookJob',
        'error_message' => 'Test error message',
        'payload' => ['field' => 'messages', 'value' => ['test' => 'data']], // Model will cast to JSON
        'attempts' => 3,
    ], $overrides));
}

describe('AC-1.5.4: Admin Index Endpoint', function () {
    it('returns paginated list of webhook failures', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create multiple failures
        for ($i = 0; $i < 5; $i++) {
            createWebhookFailure($organization->id, ['error_message' => "Error {$i}"]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/webhook-failures');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('meta.total', 5);
    });

    it('filters by organization_id', function () {
        $admin = createAdminUser();
        $org1 = Organization::factory()->withoutAppSecret()->create();
        $org2 = Organization::factory()->withoutAppSecret()->create();

        createWebhookFailure($org1->id, ['error_message' => 'Org1 Error']);
        createWebhookFailure($org2->id, ['error_message' => 'Org2 Error']);

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/webhook-failures?organization_id='.$org1->id);

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.error_message', 'Org1 Error');
    });

    it('filters by job_type', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        createWebhookFailure($organization->id, ['job_type' => 'ProcessWebhookJob']);
        createWebhookFailure($organization->id, ['job_type' => 'DownloadWebhookMediaJob']);

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/webhook-failures?job_type=ProcessWebhookJob');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.job_type', 'ProcessWebhookJob');
    });

    it('requires admin authentication', function () {
        $response = $this->getJson('/admin/webhook-failures');

        $response->assertStatus(401);
    });

    it('includes organization relationship data', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create(['name' => 'Test Organization']);

        createWebhookFailure($organization->id);

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/webhook-failures');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.organization.name', 'Test Organization');
    });
});

describe('AC-1.5.5: Admin Retry Endpoint', function () {
    it('dispatches ProcessWebhookJob when retrying', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = createWebhookFailure($organization->id);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/webhook-failures/{$failure->id}/retry");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Webhook re-queued for processing',
        ]);

        Queue::assertPushed(ProcessWebhookJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });

    it('increments attempts count after retry', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = createWebhookFailure($organization->id, ['attempts' => 3]);

        $this->actingAs($admin, 'admin')
            ->postJson("/admin/webhook-failures/{$failure->id}/retry");

        $failure->refresh();
        expect($failure->attempts)->toBe(4);
    });

    it('returns 404 for non-existent failure', function () {
        $admin = createAdminUser();

        $response = $this->actingAs($admin, 'admin')
            ->postJson('/admin/webhook-failures/99999/retry');

        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
            'message' => 'Webhook failure record not found',
        ]);
    });

    it('returns 422 when payload is empty', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = createWebhookFailure($organization->id, ['payload' => null]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson("/admin/webhook-failures/{$failure->id}/retry");

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'Cannot retry: payload is empty or invalid',
        ]);
    });

    it('requires admin authentication', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $failure = createWebhookFailure($organization->id);

        $response = $this->postJson("/admin/webhook-failures/{$failure->id}/retry");

        $response->assertStatus(401);
    });
});

describe('Admin Show Endpoint', function () {
    it('returns single webhook failure with organization', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create(['name' => 'Show Test Org']);

        $failure = createWebhookFailure($organization->id, ['error_message' => 'Show test error']);

        $response = $this->actingAs($admin, 'admin')
            ->getJson("/admin/webhook-failures/{$failure->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.error_message', 'Show test error');
        $response->assertJsonPath('data.organization.name', 'Show Test Org');
    });

    it('returns 404 for non-existent failure', function () {
        $admin = createAdminUser();

        $response = $this->actingAs($admin, 'admin')
            ->getJson('/admin/webhook-failures/99999');

        $response->assertStatus(404);
    });
});

describe('Admin Destroy Endpoint', function () {
    it('deletes webhook failure record', function () {
        $admin = createAdminUser();
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = createWebhookFailure($organization->id);
        $failureId = $failure->id;

        $response = $this->actingAs($admin, 'admin')
            ->deleteJson("/admin/webhook-failures/{$failureId}");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Webhook failure record deleted',
        ]);

        expect(WebhookFailure::find($failureId))->toBeNull();
    });

    it('returns 404 for non-existent failure', function () {
        $admin = createAdminUser();

        $response = $this->actingAs($admin, 'admin')
            ->deleteJson('/admin/webhook-failures/99999');

        $response->assertStatus(404);
    });
});
