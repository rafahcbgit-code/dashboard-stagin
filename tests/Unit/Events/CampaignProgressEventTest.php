<?php

use App\Events\CampaignProgressEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Task 2.5: AC-2.5.1 - CampaignProgressEvent broadcast channel tests
 */
describe('CampaignProgressEvent broadcast channel', function () {
    it('broadcasts to correct private channel name', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid-123',
            total: 100,
            sent: 50,
            failed: 5,
            pending: 45,
            percentComplete: 55.0,
            estimatedCompletion: '~2 minutes remaining'
        );

        // Mock pusher config to allow broadcasting
        config(['broadcasting.connections.pusher.key' => 'test-key']);
        config(['broadcasting.connections.pusher.secret' => 'test-secret']);

        $channel = $event->broadcastOn();

        expect($channel)->toBeInstanceOf(PrivateChannel::class);
        expect($channel->name)->toBe('private-campaign.test-uuid-123');
    });

    it('returns correct broadcast event name', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid',
            total: 100,
            sent: 50,
            failed: 5,
            pending: 45,
            percentComplete: 55.0
        );

        expect($event->broadcastAs())->toBe('CampaignProgressEvent');
    });
});

/**
 * Task 2.5: AC-2.5.2 - CampaignProgressEvent payload structure tests
 */
describe('CampaignProgressEvent payload structure', function () {
    it('includes all required fields in broadcast payload', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid-456',
            total: 1000,
            sent: 750,
            failed: 25,
            pending: 225,
            percentComplete: 77.5,
            estimatedCompletion: '~5 minutes remaining'
        );

        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys([
            'campaignUuid',
            'total',
            'sent',
            'failed',
            'pending',
            'percentComplete',
            'estimatedCompletion',
        ]);

        expect($payload['campaignUuid'])->toBe('test-uuid-456');
        expect($payload['total'])->toBe(1000);
        expect($payload['sent'])->toBe(750);
        expect($payload['failed'])->toBe(25);
        expect($payload['pending'])->toBe(225);
        expect($payload['percentComplete'])->toBe(77.5);
        expect($payload['estimatedCompletion'])->toBe('~5 minutes remaining');
    });

    it('handles null estimated completion', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid',
            total: 100,
            sent: 100,
            failed: 0,
            pending: 0,
            percentComplete: 100.0,
            estimatedCompletion: null
        );

        $payload = $event->broadcastWith();

        expect($payload['estimatedCompletion'])->toBeNull();
    });

    it('uses correct types for numeric fields', function () {
        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid',
            total: 100,
            sent: 50,
            failed: 10,
            pending: 40,
            percentComplete: 60.0
        );

        $payload = $event->broadcastWith();

        expect($payload['total'])->toBeInt();
        expect($payload['sent'])->toBeInt();
        expect($payload['failed'])->toBeInt();
        expect($payload['pending'])->toBeInt();
        expect($payload['percentComplete'])->toBeFloat();
    });
});

/**
 * Task 2.5: Error handling tests
 */
describe('CampaignProgressEvent error handling', function () {
    it('returns null when pusher is not configured', function () {
        config(['broadcasting.connections.pusher.key' => null]);
        config(['broadcasting.connections.pusher.secret' => null]);

        $event = new CampaignProgressEvent(
            campaignUuid: 'test-uuid',
            total: 100,
            sent: 50,
            failed: 5,
            pending: 45,
            percentComplete: 55.0
        );

        $channel = $event->broadcastOn();

        expect($channel)->toBeNull();
    });
});
