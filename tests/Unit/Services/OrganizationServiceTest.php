<?php

/**
 * OrganizationServiceTest - Story 2.2 Unit Tests
 *
 * Tests for organization metadata caching functionality.
 *
 * @see Story 2.2: Optimize Campaign Eager Loading and Caching
 * @covers App\Services\OrganizationService::getCachedMetadata
 * @covers App\Services\OrganizationService::invalidateMetadataCache
 */

use App\Models\Organization;
use App\Services\OrganizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

describe('OrganizationService::getCachedMetadata', function () {
    it('returns decoded metadata array for valid organization', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode([
                'campaigns' => [
                    'enable_resend' => true,
                    'resend_intervals' => [5, 15, 60],
                ],
                'whatsapp' => [
                    'access_token' => 'test-token',
                ],
            ]),
        ]);

        // Act
        $metadata = OrganizationService::getCachedMetadata($org->id);

        // Assert
        expect($metadata)->toBeArray();
        expect($metadata['campaigns']['enable_resend'])->toBeTrue();
        expect($metadata['campaigns']['resend_intervals'])->toBe([5, 15, 60]);
        expect($metadata['whatsapp']['access_token'])->toBe('test-token');
    });

    it('returns cached value on second call without database query (AC-2.2.2)', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode(['test' => 'value']),
        ]);

        // First call - should hit database
        $firstResult = OrganizationService::getCachedMetadata($org->id);

        // Verify cache is now populated
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Second call - should use cache
        $secondResult = OrganizationService::getCachedMetadata($org->id);

        // Assert
        expect($firstResult)->toBe($secondResult);
        expect($firstResult['test'])->toBe('value');
    });

    it('returns empty array for non-existent organization', function () {
        // Act
        $metadata = OrganizationService::getCachedMetadata(99999);

        // Assert
        expect($metadata)->toBeArray();
        expect($metadata)->toBeEmpty();
    });

    it('returns empty array for organization with null metadata', function () {
        // Arrange
        $org = Organization::factory()->create(['metadata' => null]);

        // Act
        $metadata = OrganizationService::getCachedMetadata($org->id);

        // Assert
        expect($metadata)->toBeArray();
        expect($metadata)->toBeEmpty();
    });

    it('uses correct cache key format', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode(['test' => 'data']),
        ]);

        // Act
        OrganizationService::getCachedMetadata($org->id);

        // Assert
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();
    });
});

describe('OrganizationService::invalidateMetadataCache', function () {
    it('removes cached metadata when called', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode(['test' => 'value']),
        ]);

        // Populate cache
        OrganizationService::getCachedMetadata($org->id);
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Act
        OrganizationService::invalidateMetadataCache($org->id);

        // Assert
        expect(Cache::has('org-metadata-'.$org->id))->toBeFalse();
    });

    it('does not throw when invalidating non-cached organization', function () {
        // Act & Assert - should not throw
        OrganizationService::invalidateMetadataCache(99999);
        expect(true)->toBeTrue(); // If we get here, no exception was thrown
    });
});

describe('Organization model cache invalidation (AC-2.2.2)', function () {
    it('invalidates cache when organization metadata is updated', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode(['original' => 'value']),
        ]);

        // Populate cache
        $originalMetadata = OrganizationService::getCachedMetadata($org->id);
        expect($originalMetadata['original'])->toBe('value');
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Act - Update metadata
        $org->metadata = json_encode(['updated' => 'new-value']);
        $org->save();

        // Assert - Cache should be invalidated
        expect(Cache::has('org-metadata-'.$org->id))->toBeFalse();

        // And next call should return new data
        $newMetadata = OrganizationService::getCachedMetadata($org->id);
        expect($newMetadata)->toHaveKey('updated');
        expect($newMetadata['updated'])->toBe('new-value');
    });

    it('does not invalidate cache when non-metadata fields are updated', function () {
        // Arrange
        $org = Organization::factory()->create([
            'name' => 'Original Name',
            'metadata' => json_encode(['test' => 'value']),
        ]);

        // Populate cache
        OrganizationService::getCachedMetadata($org->id);
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Act - Update non-metadata field
        $org->name = 'Updated Name';
        $org->save();

        // Assert - Cache should still exist (metadata wasn't changed)
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();
    });

    it('invalidates cache when organization is deleted', function () {
        // Arrange
        $org = Organization::factory()->create([
            'metadata' => json_encode(['test' => 'value']),
        ]);

        // Populate cache
        OrganizationService::getCachedMetadata($org->id);
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        // Act
        $org->delete();

        // Assert
        expect(Cache::has('org-metadata-'.$org->id))->toBeFalse();
    });
});

describe('OrganizationService campaign send speed settings', function () {
    it('stores campaign phone_mps without removing existing metadata and invalidates cache', function () {
        $org = Organization::factory()->create([
            'metadata' => json_encode([
                'whatsapp' => [
                    'access_token' => 'token-123',
                    'phone_number_id' => 'phone-123',
                ],
                'campaigns' => [
                    'enable_resend' => true,
                    'resend_intervals' => [5, 15],
                ],
                'timezone' => 'UTC',
            ]),
        ]);

        OrganizationService::getCachedMetadata($org->id);
        expect(Cache::has('org-metadata-'.$org->id))->toBeTrue();

        $request = Request::create('/admin/organizations/'.$org->uuid, 'PUT', [
            'name' => 'Updated Organization',
            'plan' => 'trial',
            'subscription_status' => 'active',
            'campaign_phone_mps' => 40,
            'street' => 'Main Street',
            'city' => 'Damascus',
            'state' => 'Damascus',
            'zip' => '00000',
            'country' => 'SY',
        ]);

        (new OrganizationService())->update($request, $org->uuid);

        $org->refresh();
        $metadata = json_decode($org->metadata, true);

        expect($metadata['campaigns']['phone_mps'])->toBe(40);
        expect($metadata['campaigns']['enable_resend'])->toBeTrue();
        expect($metadata['campaigns']['resend_intervals'])->toBe([5, 15]);
        expect($metadata['whatsapp']['access_token'])->toBe('token-123');
        expect($metadata['whatsapp']['phone_number_id'])->toBe('phone-123');
        expect(Cache::has('org-metadata-'.$org->id))->toBeFalse();
    });
});
