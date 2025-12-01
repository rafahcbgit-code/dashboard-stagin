<?php

/**
 * Contact Query Performance Tests
 *
 * Tests for FR21-FR30: Chat Module Performance Requirements
 * - FR21: Eliminate N+1 queries in contact list loading
 * - FR22: Database indexes on critical columns
 * - FR23: Use materialized `latest_chat_created_at` column
 *
 * @see docs/test-design-system.md
 * @see docs/prd.md (FR21-FR30)
 */

use App\Models\Contact;
use App\Models\Organization;

describe('Contact List Performance (FR21)', function () {
    it('loads contact list with chats in under 500ms', function () {
        // Arrange: Create test data
        $organization = createTestOrganization();

        // Create 100 contacts with chats for realistic load test
        Contact::factory()
            ->count(100)
            ->for($organization)
            ->create();

        // Act: Measure query time
        $duration = measureTime(function () use ($organization) {
            Contact::contactsWithChats($organization->id)
                ->take(50)
                ->get();
        });

        // Assert: Should load in under 500ms (NFR2)
        expect($duration)->toRespondWithin(500);
    })->skip('ContactFactory not yet defined');

    it('reduces query count by 60% compared to baseline', function () {
        // Arrange
        $organization = createTestOrganization();

        // Baseline: Current implementation (before optimization)
        // Target: 60% reduction (FR4)

        $queryCount = countQueries(function () use ($organization) {
            Contact::contactsWithChats($organization->id)
                ->take(50)
                ->get();
        });

        // After optimization, query count should be significantly reduced
        // Baseline was ~1500 queries per 500 logs
        // Target is < 600 queries per 500 logs
        expect($queryCount)->toBeLessThan(100); // For 50 contacts
    })->skip('Query count baseline not yet established');
});

describe('Materialized Column (FR23)', function () {
    it('uses latest_chat_created_at column instead of subquery', function () {
        // Arrange
        $organization = createTestOrganization();

        // Act: Get the query SQL
        $query = Contact::contactsWithChats($organization->id)->toSql();

        // Assert: Should NOT contain correlated subquery
        // After optimization, should use materialized column
        expect($query)->not->toContain('SELECT MAX(created_at) FROM chats');
    })->skip('Optimization not yet implemented');
});

describe('Database Indexes (FR22)', function () {
    it('has required composite indexes for performance', function () {
        // This is a schema validation test
        // Verify indexes exist on:
        // - contacts(organization_id, latest_chat_created_at)
        // - chats(contact_id, created_at)
        // - chat_tickets(contact_id, assigned_to, status)
        // - contact_label(contact_id, label_id)

        $indexes = \DB::select("SHOW INDEX FROM contacts WHERE Key_name = 'idx_contacts_org_latest_chat'");

        expect($indexes)->not->toBeEmpty();
    })->skip('Index migration not yet run');
});
