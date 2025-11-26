<?php

/**
 * WebhookFailureTest - Story 1.5 Tests
 *
 * Tests for WebhookFailure model implementation covering:
 * - Model fillable and casts (AC-1.5.2)
 * - Organization relationship
 * - Scope methods
 *
 * @see docs/sprint-artifacts/1-5-implement-webhook-retry-and-failure-handling.md
 */

use App\Models\Organization;
use App\Models\WebhookFailure;

describe('WebhookFailure Model', function () {
    it('can be created with fillable attributes', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = WebhookFailure::create([
            'organization_id' => $organization->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Test error message',
            'payload' => ['test' => 'data'], // Model will cast to JSON
            'attempts' => 3,
        ]);

        expect($failure)->not->toBeNull();
        expect($failure->id)->toBeGreaterThan(0);
        expect($failure->organization_id)->toBe($organization->id);
        expect($failure->job_type)->toBe('ProcessWebhookJob');
        expect($failure->error_message)->toBe('Test error message');
        expect($failure->attempts)->toBe(3);
    });

    it('casts payload as array when retrieved', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payloadData = ['field' => 'messages', 'value' => ['test' => true]];

        $failure = WebhookFailure::create([
            'organization_id' => $organization->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Test error',
            'payload' => $payloadData, // Model will cast to JSON on save
            'attempts' => 1,
        ]);

        // Reload from database
        $failure->refresh();

        expect($failure->payload)->toBeArray();
        expect($failure->payload['field'])->toBe('messages');
    });

    it('casts created_at as datetime', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = WebhookFailure::create([
            'organization_id' => $organization->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Test error',
            'attempts' => 1,
        ]);

        $failure->refresh();

        expect($failure->created_at)->toBeInstanceOf(\Carbon\Carbon::class);
    });

    it('can store optional fields like chat_id, contact_id, media_id', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = WebhookFailure::create([
            'organization_id' => $organization->id,
            'job_type' => 'DownloadWebhookMediaJob',
            'chat_id' => 123,
            'contact_id' => 456,
            'media_id' => 'media_abc123',
            'media_type' => 'image',
            'error_message' => 'Download failed',
            'error_code' => '500',
            'attempts' => 2,
        ]);

        expect($failure->chat_id)->toBe(123);
        expect($failure->contact_id)->toBe(456);
        expect($failure->media_id)->toBe('media_abc123');
        expect($failure->media_type)->toBe('image');
        expect($failure->error_code)->toBe('500');
    });
});

describe('WebhookFailure Relationships', function () {
    it('belongs to an organization', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $failure = WebhookFailure::create([
            'organization_id' => $organization->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Test error',
            'attempts' => 1,
        ]);

        expect($failure->organization)->not->toBeNull();
        expect($failure->organization->id)->toBe($organization->id);
        expect($failure->organization->name)->toBe($organization->name);
    });
});

describe('WebhookFailure Scopes', function () {
    it('scopeForOrganization filters by organization_id', function () {
        $org1 = Organization::factory()->withoutAppSecret()->create();
        $org2 = Organization::factory()->withoutAppSecret()->create();

        WebhookFailure::create([
            'organization_id' => $org1->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Error for org1',
            'attempts' => 1,
        ]);

        WebhookFailure::create([
            'organization_id' => $org2->id,
            'job_type' => 'ProcessWebhookJob',
            'error_message' => 'Error for org2',
            'attempts' => 1,
        ]);

        $org1Failures = WebhookFailure::forOrganization($org1->id)->get();
        $org2Failures = WebhookFailure::forOrganization($org2->id)->get();

        expect($org1Failures)->toHaveCount(1);
        expect($org1Failures->first()->error_message)->toBe('Error for org1');

        expect($org2Failures)->toHaveCount(1);
        expect($org2Failures->first()->error_message)->toBe('Error for org2');
    });
});
