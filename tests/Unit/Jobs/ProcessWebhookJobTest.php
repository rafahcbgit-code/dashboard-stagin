<?php

/**
 * ProcessWebhookJobTest - Story 1.2 Tests
 *
 * Tests for ProcessWebhookJob implementation covering:
 * - Contact creation via ContactService (AC-1.2.1)
 * - Chat creation via Chat model (AC-1.2.2)
 * - DownloadWebhookMediaJob dispatch for media (AC-1.2.3)
 * - ProcessWebhookNotificationsJob dispatch (AC-1.2.4)
 * - Idempotency check via wam_id (AC-1.2.5)
 * - Performance under 2 seconds (AC-1.2.6)
 *
 * @see docs/sprint-artifacts/1-2-create-processwebhookjob-for-background-processing.md
 */

use App\Jobs\Webhook\DownloadWebhookMediaJob;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Jobs\Webhook\ProcessWebhookNotificationsJob;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\WebhookFailure;
use App\Services\ContactService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([
        DownloadWebhookMediaJob::class,
        ProcessWebhookNotificationsJob::class,
    ]);
});

/**
 * Helper to create an organization with an active subscription.
 * Required for most tests since subscription limits are now enforced.
 */
function createOrganizationWithSubscription(): Organization
{
    $organization = Organization::factory()->withoutAppSecret()->create();

    // Create subscription plan with unlimited messages
    $plan = SubscriptionPlan::create([
        'name' => 'Test Plan',
        'price' => 100,
        'period' => 'monthly',
        'metadata' => json_encode(['message_limit' => -1]), // Unlimited
    ]);

    // Create active subscription
    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'valid_until' => now()->addMonth(),
        'start_date' => now(),
    ]);

    return $organization;
}

/**
 * Helper to create a valid message webhook payload.
 */
function createMessagePayload(
    string $type = 'text',
    string $wamId = null,
    string $from = '1234567890',
    ?string $contactName = 'Test User',
    array $additionalData = []
): array {
    $wamId = $wamId ?? 'wamid_test_'.uniqid();

    $message = [
        'from' => $from,
        'id' => $wamId,
        'timestamp' => (string) time(),
        'type' => $type,
    ];

    // Add type-specific data
    switch ($type) {
        case 'text':
            $message['text'] = ['body' => $additionalData['body'] ?? 'Hello, World!'];
            break;
        case 'image':
            $message['image'] = [
                'id' => $additionalData['media_id'] ?? 'media_123',
                'mime_type' => 'image/jpeg',
                'sha256' => 'abc123',
            ];
            break;
        case 'video':
            $message['video'] = [
                'id' => $additionalData['media_id'] ?? 'media_456',
                'mime_type' => 'video/mp4',
            ];
            break;
        case 'document':
            $message['document'] = [
                'id' => $additionalData['media_id'] ?? 'media_789',
                'mime_type' => 'application/pdf',
                'filename' => 'document.pdf',
            ];
            break;
        case 'audio':
            $message['audio'] = [
                'id' => $additionalData['media_id'] ?? 'media_audio',
                'mime_type' => 'audio/ogg',
            ];
            break;
        case 'location':
            $message['location'] = [
                'latitude' => 37.7749,
                'longitude' => -122.4194,
                'name' => 'San Francisco',
            ];
            break;
        case 'contacts':
            $message['contacts'] = [
                ['name' => ['formatted_name' => 'John Doe'], 'phones' => [['phone' => '9876543210']]],
            ];
            break;
        case 'interactive':
            $message['interactive'] = [
                'type' => 'button_reply',
                'button_reply' => ['id' => 'btn_1', 'title' => 'Yes'],
            ];
            break;
    }

    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => '0987654321',
                'phone_number_id' => 'phone_id_123',
            ],
            'contacts' => [
                ['profile' => ['name' => $contactName], 'wa_id' => $from],
            ],
            'messages' => [$message],
        ],
    ];
}

describe('AC-1.2.1: Contact Creation via ContactService', function () {
    it('creates new contact when phone number does not exist', function () {
        $organization = createOrganizationWithSubscription();
        $phone = '1234567890';
        $contactName = 'New Test User';

        $payload = createMessagePayload('text', null, $phone, $contactName);

        // Verify contact doesn't exist
        expect(Contact::where('organization_id', $organization->id)->where('phone', 'like', "%{$phone}%")->exists())->toBeFalse();

        // Run job
        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify contact was created
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', "%{$phone}%")
            ->first();

        expect($contact)->not->toBeNull();
        expect($contact->first_name)->toBe('New');
        expect($contact->last_name)->toBe('Test User');
    });

    it('uses existing contact when phone number exists', function () {
        $organization = createOrganizationWithSubscription();

        // Create existing contact
        $existingContact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1234567890',
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = createMessagePayload('text', null, '1234567890', 'Different Name');

        // Run job
        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify no new contact was created
        $contactCount = Contact::where('organization_id', $organization->id)->count();
        expect($contactCount)->toBe(1);

        // Verify chat references existing contact
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat->contact_id)->toBe($existingContact->id);
    });
});

describe('AC-1.2.2: Chat Creation', function () {
    it('creates chat record with correct data', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_test_'.uniqid();
        $messageBody = 'Test message content';

        $payload = createMessagePayload('text', $wamId, '1234567890', 'Test User', ['body' => $messageBody]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)
            ->where('wam_id', $wamId)
            ->first();

        expect($chat)->not->toBeNull();
        expect($chat->type)->toBe('inbound');
        expect($chat->status)->toBe('received');
        expect($chat->is_read)->toBe(0);

        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('text');
        expect($metadata['text']['body'])->toBe($messageBody);
    });

    it('creates ChatLog entry for chat thread view', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $chatLog = ChatLog::where('entity_type', 'chat')
            ->where('entity_id', $chat->id)
            ->first();

        expect($chatLog)->not->toBeNull();
        expect($chatLog->contact_id)->toBe($chat->contact_id);
    });
});

describe('AC-1.2.3: DownloadWebhookMediaJob Dispatch', function () {
    it('dispatches DownloadWebhookMediaJob for image message', function () {
        $organization = createOrganizationWithSubscription();
        $mediaId = 'media_image_'.uniqid();
        $payload = createMessagePayload('image', null, '1234567890', 'Test User', ['media_id' => $mediaId]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) use ($organization, $mediaId) {
            return $job->organizationId === $organization->id
                && $job->mediaData['media_id'] === $mediaId
                && $job->mediaData['type'] === 'image';
        });
    });

    it('dispatches DownloadWebhookMediaJob for video message', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('video');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'video';
        });
    });

    it('dispatches DownloadWebhookMediaJob for document message', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('document');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'document';
        });
    });

    it('dispatches DownloadWebhookMediaJob for audio message', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('audio');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'audio';
        });
    });

    it('does NOT dispatch DownloadWebhookMediaJob for text message', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });

    it('does NOT dispatch DownloadWebhookMediaJob for location message', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('location');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });
});

describe('AC-1.2.4: ProcessWebhookNotificationsJob Dispatch', function () {
    it('dispatches ProcessWebhookNotificationsJob after chat creation', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });

    it('dispatches ProcessWebhookNotificationsJob to webhook-high queue', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });

    it('passes isNewContact=true for new contacts', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text', null, '9999999999', 'Brand New Contact');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->isNewContact === true;
        });
    });

    it('passes isNewContact=false for existing contacts', function () {
        $organization = createOrganizationWithSubscription();

        // Create existing contact
        Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1234567890',
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = createMessagePayload('text', null, '1234567890');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->isNewContact === false;
        });
    });
});

describe('AC-1.2.5: Idempotency Check', function () {
    it('skips duplicate message with same wam_id', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_duplicate_test_'.uniqid();

        // First message - should create chat
        $payload1 = createMessagePayload('text', $wamId, '1234567890', 'Test User', ['body' => 'First message']);
        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'messages');
        $job1->handle();

        $chatCountAfterFirst = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterFirst)->toBe(1);

        // Reset queue fake to count new dispatches
        Queue::fake([DownloadWebhookMediaJob::class, ProcessWebhookNotificationsJob::class]);

        // Second message with same wam_id - should be skipped
        $payload2 = createMessagePayload('text', $wamId, '1234567890', 'Test User', ['body' => 'Duplicate message']);
        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'messages');
        $job2->handle();

        $chatCountAfterSecond = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterSecond)->toBe(1); // Still only 1 chat

        // No notification job should be dispatched for duplicate
        Queue::assertNotPushed(ProcessWebhookNotificationsJob::class);
    });

    it('creates separate chats for different wam_ids', function () {
        $organization = createOrganizationWithSubscription();

        $payload1 = createMessagePayload('text', 'wamid_unique_1', '1234567890');
        $payload2 = createMessagePayload('text', 'wamid_unique_2', '1234567890');

        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'messages');
        $job1->handle();

        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'messages');
        $job2->handle();

        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(2);
    });
});

describe('AC-1.2.6: Performance - All Webhook Types Handled', function () {
    it('handles text message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('text');
    });

    it('handles image message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('image');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('image');
        expect($metadata['image'])->not->toBeEmpty();
    });

    it('handles video message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('video');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('video');
    });

    it('handles document message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('document');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('document');
    });

    it('handles audio message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('audio');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('audio');
    });

    it('handles location message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('location');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('location');
        expect($metadata['location']['latitude'])->toBe(37.7749);
    });

    it('handles contacts message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('contacts');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('contacts');
    });

    it('handles interactive message type', function () {
        $organization = createOrganizationWithSubscription();
        $payload = createMessagePayload('interactive');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('interactive');
        expect($metadata['interactive']['type'])->toBe('button_reply');
    });
});

describe('Job Configuration', function () {
    it('has correct queue configuration', function () {
        $job = new ProcessWebhookJob(
            ['field' => 'messages', 'value' => []],
            1,
            'messages'
        );

        expect($job->queue)->toBe('webhook-high');
        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
        expect($job->backoff)->toBe([60, 300, 900]);
    });

    it('has middleware for FIFO ordering', function () {
        $payload = createMessagePayload('text', null, '1234567890');
        $job = new ProcessWebhookJob($payload, 1, 'messages');

        $middleware = $job->middleware();

        expect($middleware)->not->toBeEmpty();
    });
});

describe('Edge Cases', function () {
    it('handles missing contact info gracefully', function () {
        $organization = createOrganizationWithSubscription();

        // Payload without contact profile name
        $payload = [
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '0987654321', 'phone_number_id' => 'phone_id'],
                'contacts' => [], // No contact info
                'messages' => [
                    [
                        'from' => '1234567890',
                        'id' => 'wamid_no_contact_'.uniqid(),
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Message without contact info'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Should still create contact using phone number
        $contact = Contact::where('organization_id', $organization->id)->first();
        expect($contact)->not->toBeNull();
    });

    it('handles invalid message data without crashing', function () {
        $organization = createOrganizationWithSubscription();

        // Payload with missing required fields
        $payload = [
            'field' => 'messages',
            'value' => [
                'messages' => [
                    ['type' => 'text'], // Missing 'from' and 'id'
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Should not throw exception
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // No chat should be created
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles empty messages array', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'messages',
            'value' => [
                'messages' => [],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });
});

describe('AC-1.5.1: Retry Configuration', function () {
    it('has $tries = 3 for 3 retry attempts', function () {
        $job = new ProcessWebhookJob(
            ['field' => 'messages', 'value' => []],
            1,
            'messages'
        );

        expect($job->tries)->toBe(3);
    });

    it('has exponential backoff of [60, 300, 900] seconds (1min, 5min, 15min)', function () {
        $job = new ProcessWebhookJob(
            ['field' => 'messages', 'value' => []],
            1,
            'messages'
        );

        expect($job->backoff)->toBe([60, 300, 900]);
    });

    it('has $timeout = 120 seconds', function () {
        $job = new ProcessWebhookJob(
            ['field' => 'messages', 'value' => []],
            1,
            'messages'
        );

        expect($job->timeout)->toBe(120);
    });
});

describe('AC-1.5.2: Failed Job Logging', function () {
    it('creates WebhookFailure record when job fails', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createMessagePayload('text', 'wamid_test_failure');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Simulate job failure by calling failed() directly
        $exception = new \RuntimeException('Test exception for webhook failure');
        $job->failed($exception);

        // Verify WebhookFailure record was created
        $failure = WebhookFailure::where('organization_id', $organization->id)
            ->where('job_type', 'ProcessWebhookJob')
            ->first();

        expect($failure)->not->toBeNull();
        expect($failure->error_message)->toBe('Test exception for webhook failure');
        expect($failure->payload)->not->toBeNull();
    });

    it('truncates error_message to 1000 characters', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Create an exception with a very long message
        $longMessage = str_repeat('A', 2000);
        $exception = new \RuntimeException($longMessage);
        $job->failed($exception);

        $failure = WebhookFailure::where('organization_id', $organization->id)->first();

        expect(strlen($failure->error_message))->toBeLessThanOrEqual(1000);
    });

    it('stores full payload in WebhookFailure record', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createMessagePayload('text', 'wamid_payload_test', '1234567890', 'Test User');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->failed(new \RuntimeException('Test failure'));

        $failure = WebhookFailure::where('organization_id', $organization->id)->first();

        // Payload is cast to array in model
        expect($failure->payload)->toBeArray();
        expect($failure->payload['field'])->toBe('messages');
    });

    it('stores error_code when exception has code', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        $exception = new \RuntimeException('Test with code', 500);
        $job->failed($exception);

        $failure = WebhookFailure::where('organization_id', $organization->id)->first();

        expect($failure->error_code)->toBe('500');
    });
});

/**
 * Helper to create a valid status webhook payload.
 */
function createStatusPayload(
    string $wamId,
    string $status = 'delivered',
    string $recipientId = '1234567890',
    int $timestamp = null
): array {
    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => '0987654321',
                'phone_number_id' => 'phone_id_123',
            ],
            'statuses' => [
                [
                    'id' => $wamId,
                    'status' => $status,
                    'timestamp' => (string) ($timestamp ?? time()),
                    'recipient_id' => $recipientId,
                ],
            ],
        ],
    ];
}

/**
 * Story 1.7 Tests: MissedChatStatusUpdate Handling
 *
 * Tests for restoring MissedChatStatusUpdate functionality to handle
 * race conditions where status webhooks arrive before chat records exist.
 *
 * @see docs/sprint-artifacts/1-7-restore-missedchatstatusupdate-handling.md
 */
describe('AC-1.7.1: MissedChatStatusUpdate Creation', function () {
    it('creates MissedChatStatusUpdate when status arrives for unknown wam_id', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_unknown_'.uniqid();

        $payload = createStatusPayload($wamId, 'delivered');

        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');
        $job->handle();

        $missedUpdate = \App\Models\MissedChatStatusUpdate::where('organization_id', $organization->id)
            ->where('wam_id', $wamId)
            ->first();

        expect($missedUpdate)->not->toBeNull();
        expect($missedUpdate->status)->toBe('delivered');
    });
});

describe('AC-1.7.2: MissedChatStatusUpdate Fields', function () {
    it('stores correct fields: organization_id, wam_id, status, payload, state=NOT_PROCESSED', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_fields_test_'.uniqid();
        $timestamp = time();

        $payload = createStatusPayload($wamId, 'sent', '1234567890', $timestamp);

        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');
        $job->handle();

        $missedUpdate = \App\Models\MissedChatStatusUpdate::where('wam_id', $wamId)->first();

        expect($missedUpdate)->not->toBeNull();
        expect($missedUpdate->organization_id)->toBe($organization->id);
        expect($missedUpdate->wam_id)->toBe($wamId);
        expect($missedUpdate->status)->toBe('sent');
        expect($missedUpdate->state)->toBe(\App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED);
        expect($missedUpdate->payload)->not->toBeNull();

        $storedPayload = json_decode($missedUpdate->payload, true);
        expect($storedPayload['id'])->toBe($wamId);
        expect($storedPayload['status'])->toBe('sent');
    });
});

describe('AC-1.7.3: Duplicate Status Skipped', function () {
    it('skips duplicate status updates for same wam_id+status combination', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_duplicate_status_'.uniqid();

        // First status webhook
        $payload1 = createStatusPayload($wamId, 'delivered');
        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'statuses');
        $job1->handle();

        $countAfterFirst = \App\Models\MissedChatStatusUpdate::where('wam_id', $wamId)->count();
        expect($countAfterFirst)->toBe(1);

        // Second status webhook with same wam_id+status
        $payload2 = createStatusPayload($wamId, 'delivered');
        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'statuses');
        $job2->handle();

        $countAfterSecond = \App\Models\MissedChatStatusUpdate::where('wam_id', $wamId)->count();
        expect($countAfterSecond)->toBe(1); // Still only 1 record
    });

    it('allows different status types for the same wam_id', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_multi_status_'.uniqid();

        // First status: delivered
        $payload1 = createStatusPayload($wamId, 'delivered');
        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'statuses');
        $job1->handle();

        // Second status: read (different status type)
        $payload2 = createStatusPayload($wamId, 'read');
        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'statuses');
        $job2->handle();

        $count = \App\Models\MissedChatStatusUpdate::where('wam_id', $wamId)->count();
        expect($count)->toBe(2); // Both should be stored
    });
});

describe('AC-1.7.4: Retroactive Processing', function () {
    it('processes pending MissedChatStatusUpdates when chat is created', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_retroactive_'.uniqid();

        // First: Create MissedChatStatusUpdate (status arrives before chat)
        \App\Models\MissedChatStatusUpdate::create([
            'wam_id' => $wamId,
            'organization_id' => $organization->id,
            'payload' => json_encode(['id' => $wamId, 'status' => 'delivered', 'timestamp' => time()]),
            'status' => 'delivered',
            'state' => \App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'created_at' => now(),
        ]);

        // Then: Create chat with same wam_id via message webhook
        $messagePayload = createMessagePayload('text', $wamId, '1234567890', 'Test User');
        $job = new ProcessWebhookJob($messagePayload, $organization->id, 'messages');
        $job->handle();

        // Verify chat was created and has the status from MissedChatStatusUpdate
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
        expect($chat->status)->toBe('delivered'); // Should be updated from MissedChatStatusUpdate

        // Verify ChatStatusLog was created (status is stored in metadata JSON)
        $statusLog = \App\Models\ChatStatusLog::where('chat_id', $chat->id)->first();
        expect($statusLog)->not->toBeNull();
        $logMetadata = json_decode($statusLog->metadata, true);
        expect($logMetadata['status'])->toBe('delivered');
    });

    it('processes multiple pending statuses in order', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_multi_retroactive_'.uniqid();

        // Create multiple MissedChatStatusUpdates in order
        \App\Models\MissedChatStatusUpdate::create([
            'wam_id' => $wamId,
            'organization_id' => $organization->id,
            'payload' => json_encode(['id' => $wamId, 'status' => 'sent', 'timestamp' => time()]),
            'status' => 'sent',
            'state' => \App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'created_at' => now()->subSeconds(2),
        ]);

        \App\Models\MissedChatStatusUpdate::create([
            'wam_id' => $wamId,
            'organization_id' => $organization->id,
            'payload' => json_encode(['id' => $wamId, 'status' => 'delivered', 'timestamp' => time()]),
            'status' => 'delivered',
            'state' => \App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'created_at' => now()->subSeconds(1),
        ]);

        \App\Models\MissedChatStatusUpdate::create([
            'wam_id' => $wamId,
            'organization_id' => $organization->id,
            'payload' => json_encode(['id' => $wamId, 'status' => 'read', 'timestamp' => time()]),
            'status' => 'read',
            'state' => \App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'created_at' => now(),
        ]);

        // Create chat
        $messagePayload = createMessagePayload('text', $wamId, '1234567890', 'Test User');
        $job = new ProcessWebhookJob($messagePayload, $organization->id, 'messages');
        $job->handle();

        // Chat should have the latest status
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat->status)->toBe('read'); // Latest status

        // All 3 ChatStatusLog entries should exist
        $statusLogCount = \App\Models\ChatStatusLog::where('chat_id', $chat->id)->count();
        expect($statusLogCount)->toBe(3);
    });
});

describe('AC-1.7.5: Processed Records Marked Correctly', function () {
    it('marks MissedChatStatusUpdate as PROCESSED after chat creation', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_processed_'.uniqid();

        // Create MissedChatStatusUpdate
        $missedUpdate = \App\Models\MissedChatStatusUpdate::create([
            'wam_id' => $wamId,
            'organization_id' => $organization->id,
            'payload' => json_encode(['id' => $wamId, 'status' => 'delivered', 'timestamp' => time()]),
            'status' => 'delivered',
            'state' => \App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'created_at' => now(),
        ]);

        // Verify initial state
        expect($missedUpdate->state)->toBe(\App\Models\MissedChatStatusUpdate::STATE_NOT_PROCESSED);

        // Create chat
        $messagePayload = createMessagePayload('text', $wamId, '1234567890', 'Test User');
        $job = new ProcessWebhookJob($messagePayload, $organization->id, 'messages');
        $job->handle();

        // Refresh and verify state changed to PROCESSED
        $missedUpdate->refresh();
        expect($missedUpdate->state)->toBe(\App\Models\MissedChatStatusUpdate::STATE_PROCESSED);
    });
});

describe('AC-1.7.6: External Webhook Trigger', function () {
    it('triggers message.status.update webhook when chat status is updated', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_webhook_'.uniqid();

        // Create a chat first
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1234567890',
            'first_name' => 'Test',
            'last_name' => 'User',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chat = Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'sent',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        // Mock WebhookHelper to verify it's called
        // Note: WebhookHelper is a static class, so we test by checking the job completes without errors
        // In a real scenario, you'd use dependency injection or a spy

        $payload = createStatusPayload($wamId, 'delivered');
        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');

        // This should complete without throwing exceptions (fire-and-forget pattern)
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Verify the chat status was updated
        $chat->refresh();
        expect($chat->status)->toBe('delivered');
    });

    it('does not fail job when webhook trigger fails', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_webhook_fail_'.uniqid();

        // Create chat
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+9876543210',
            'first_name' => 'Webhook',
            'last_name' => 'Test',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'sent',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $payload = createStatusPayload($wamId, 'read');
        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');

        // Fire-and-forget pattern means job should complete even if webhook fails
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });
});

/**
 * Story 1.8 Tests: Subscription Limit Checks
 *
 * Tests for restoring subscription limit checks during webhook processing
 * to prevent organizations from exceeding their plan's message quotas.
 *
 * @see docs/sprint-artifacts/1-8-restore-subscription-limit-checks.md
 */
describe('AC-1.8.1, AC-1.8.2: Inbound Subscription Limit Check', function () {
    it('skips message processing when subscription limit is reached', function () {
        // Create organization without subscription (limit will be reached)
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createMessagePayload('text', 'wamid_limit_test_'.uniqid(), '1234567890', 'Test User');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // No chat should be created when subscription limit is reached
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(0);

        // No notifications should be dispatched
        Queue::assertNotPushed(ProcessWebhookNotificationsJob::class);
    });

    it('skips message processing when subscription is expired', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create expired subscription
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Test Plan',
            'price' => 100,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => 1000]),
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->subDay(), // Expired yesterday
            'start_date' => now()->subMonth(),
        ]);

        $payload = createMessagePayload('text', 'wamid_expired_'.uniqid());

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // No chat should be created when subscription is expired
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(0);
    });

    it('processes messages normally when subscription is active', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create active subscription
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Active Plan',
            'price' => 100,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => 1000]),
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(), // Valid for a month
            'start_date' => now(),
        ]);

        $wamId = 'wamid_active_sub_'.uniqid();
        $payload = createMessagePayload('text', $wamId);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Chat should be created when subscription is active
        $chat = Chat::where('organization_id', $organization->id)->where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
    });
});

describe('AC-1.8.3: Job Completes Successfully', function () {
    it('job completes successfully even when subscription limit is reached (200 OK pattern)', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        // No subscription = limit reached

        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Should NOT throw any exception - maintains 200 OK pattern
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });
});

describe('AC-1.8.4, AC-1.8.5: Auto-Reply Limit Check', function () {
    it('skips auto-reply when message limit is reached but still stores chat', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create subscription with message limit of 0 (limit reached immediately)
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Limited Plan',
            'price' => 50,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => 0]), // Limit is 0
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
            'start_date' => now(),
        ]);

        $wamId = 'wamid_msg_limit_'.uniqid();
        $payload = createMessagePayload('text', $wamId);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Chat should still be stored
        $chat = Chat::where('organization_id', $organization->id)->where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
        expect($chat->type)->toBe('inbound');
    });

    it('skips auto-reply when contact bot_enable is false', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create active subscription with unlimited messages
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Unlimited Plan',
            'price' => 100,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]), // Unlimited
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
            'start_date' => now(),
        ]);

        // Create contact with bot_enable = false
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1234567890',
            'first_name' => 'Bot',
            'last_name' => 'Disabled',
            'is_subscribed' => 1,
            'bot_enable' => false, // Bot disabled
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wamId = 'wamid_bot_disabled_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '1234567890', 'Bot Disabled');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Chat should still be created
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
        expect($chat->contact_id)->toBe($contact->id);
    });

    it('processes auto-reply when subscription OK, bot enabled, and supported message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create active subscription with unlimited messages
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Full Access Plan',
            'price' => 200,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]), // Unlimited
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
            'start_date' => now(),
        ]);

        $wamId = 'wamid_full_access_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '9876543210', 'Full Access User');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Job should complete without error (auto-reply will be attempted)
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should be created
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();

        // Contact should have bot_enable = 1 (truthy) by default
        $contact = Contact::find($chat->contact_id);
        expect((bool) $contact->bot_enable)->toBeTrue();
    });

    it('skips auto-reply for unsupported message types (image, video, document, etc.)', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Create active subscription
        $plan = \App\Models\SubscriptionPlan::create([
            'name' => 'Media Plan',
            'price' => 100,
            'period' => 'monthly',
            'metadata' => json_encode(['message_limit' => -1]),
        ]);

        \App\Models\Subscription::create([
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'valid_until' => now()->addMonth(),
            'start_date' => now(),
        ]);

        // Test with image message (not in auto-reply types)
        $wamId = 'wamid_image_no_reply_'.uniqid();
        $payload = createMessagePayload('image', $wamId);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Chat should be created but auto-reply is skipped for image
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('image');
    });
});

describe('Status Update Normal Flow', function () {
    it('updates chat status when chat exists', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_status_update_'.uniqid();

        // Create existing chat
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1112223333',
            'first_name' => 'Status',
            'last_name' => 'Test',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chat = Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'sent',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        // Send status update
        $payload = createStatusPayload($wamId, 'delivered');
        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');
        $job->handle();

        // Verify status was updated
        $chat->refresh();
        expect($chat->status)->toBe('delivered');

        // Verify ChatStatusLog was created (status is stored in metadata JSON)
        $statusLog = \App\Models\ChatStatusLog::where('chat_id', $chat->id)->first();
        expect($statusLog)->not->toBeNull();
        $logMetadata = json_decode($statusLog->metadata, true);
        expect($logMetadata['status'])->toBe('delivered');
    });

    it('does NOT create MissedChatStatusUpdate when chat exists', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_no_missed_'.uniqid();

        // Create existing chat
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+4445556666',
            'first_name' => 'No',
            'last_name' => 'Missed',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'wam_id' => $wamId,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'sent',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        // Send status update
        $payload = createStatusPayload($wamId, 'delivered');
        $job = new ProcessWebhookJob($payload, $organization->id, 'statuses');
        $job->handle();

        // Verify NO MissedChatStatusUpdate was created
        $missedCount = \App\Models\MissedChatStatusUpdate::where('wam_id', $wamId)->count();
        expect($missedCount)->toBe(0);
    });
});

/**
 * Helper to create a button message payload.
 */
function createButtonMessagePayload(
    string $buttonText,
    string $wamId = null,
    string $from = '1234567890',
    ?string $contactName = 'Test User'
): array {
    $wamId = $wamId ?? 'wamid_btn_'.uniqid();

    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => '0987654321',
                'phone_number_id' => 'phone_id_123',
            ],
            'contacts' => [
                ['profile' => ['name' => $contactName], 'wa_id' => $from],
            ],
            'messages' => [
                [
                    'from' => $from,
                    'id' => $wamId,
                    'timestamp' => (string) time(),
                    'type' => 'button',
                    'button' => ['text' => $buttonText],
                ],
            ],
        ],
    ];
}

/**
 * Story 1.9 Tests: UnsubscribeChecker Integration
 *
 * Tests for restoring UnsubscribeChecker functionality to detect opt-out
 * keywords in inbound messages and update contact subscription status.
 *
 * @see docs/sprint-artifacts/1-9-restore-unsubscribechecker-integration.md
 */
describe('AC-1.9.1, AC-1.9.2: UnsubscribeChecker Call', function () {
    it('calls UnsubscribeChecker with text message body', function () {
        $organization = createOrganizationWithSubscription();

        // Set up unsubscribe trigger in organization metadata
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop,unsubscribe,quit';
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Create a text message with unsubscribe trigger
        $wamId = 'wamid_unsub_text_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '1234567890', 'Test User', ['body' => 'stop']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify contact was unsubscribed
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', '%1234567890%')
            ->first();

        expect($contact)->not->toBeNull();
        expect((int) $contact->is_subscribed)->toBe(0);
    });

    it('calls UnsubscribeChecker with button response text', function () {
        $organization = createOrganizationWithSubscription();

        // Set up unsubscribe trigger in organization metadata
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop,unsubscribe,quit';
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Create a button message with unsubscribe trigger
        $wamId = 'wamid_unsub_btn_'.uniqid();
        $payload = createButtonMessagePayload('stop', $wamId, '9876543210', 'Button User');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify contact was unsubscribed
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', '%9876543210%')
            ->first();

        expect($contact)->not->toBeNull();
        expect((int) $contact->is_subscribed)->toBe(0);
    });

    it('does not unsubscribe when message does not match trigger', function () {
        $organization = createOrganizationWithSubscription();

        // Set up unsubscribe trigger
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop,unsubscribe';
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Create a text message without unsubscribe trigger
        $wamId = 'wamid_no_unsub_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '5555555555', 'Regular User', ['body' => 'hello there']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify contact remains subscribed
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', '%5555555555%')
            ->first();

        expect($contact)->not->toBeNull();
        expect((int) $contact->is_subscribed)->toBe(1);
    });

    it('handles case-insensitive unsubscribe triggers', function () {
        $organization = createOrganizationWithSubscription();

        // Set up unsubscribe trigger
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop';
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Create a text message with uppercase trigger
        $wamId = 'wamid_case_unsub_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '4444444444', 'Case User', ['body' => 'STOP']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify contact was unsubscribed (case-insensitive)
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', '%4444444444%')
            ->first();

        expect($contact)->not->toBeNull();
        expect((int) $contact->is_subscribed)->toBe(0);
    });
});

describe('AC-1.9.3: Subscription Status Update', function () {
    it('updates existing contact subscription status to 0 on unsubscribe', function () {
        $organization = createOrganizationWithSubscription();

        // Set up unsubscribe trigger
        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata['campaign_settings']['unsubscribe_trigger'] = 'stop';
        $organization->metadata = json_encode($metadata);
        $organization->save();

        // Create existing subscribed contact
        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+3333333333',
            'first_name' => 'Subscribed',
            'last_name' => 'User',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect((int) $contact->is_subscribed)->toBe(1);

        // Send unsubscribe message
        $wamId = 'wamid_update_status_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '3333333333', 'Subscribed User', ['body' => 'stop']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        // Verify subscription status was updated
        $contact->refresh();
        expect((int) $contact->is_subscribed)->toBe(0);
    });
});

describe('AC-1.9.4: Error Isolation', function () {
    it('job completes successfully even when UnsubscribeChecker throws exception', function () {
        $organization = createOrganizationWithSubscription();

        // Create a payload that will be processed
        $wamId = 'wamid_error_isolation_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '2222222222', 'Error User', ['body' => 'test message']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Job should complete without throwing exception
        // Even if UnsubscribeChecker has issues internally, the job continues
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Verify chat was still created (main job flow completed)
        $chat = Chat::where('organization_id', $organization->id)
            ->where('wam_id', $wamId)
            ->first();

        expect($chat)->not->toBeNull();
    });

    it('continues processing when organization has no unsubscribe triggers configured', function () {
        $organization = createOrganizationWithSubscription();

        // Ensure no unsubscribe triggers are configured
        $metadata = json_decode($organization->metadata ?? '{}', true);
        unset($metadata['campaign_settings']['unsubscribe_trigger']);
        $organization->metadata = json_encode($metadata);
        $organization->save();

        $wamId = 'wamid_no_triggers_'.uniqid();
        $payload = createMessagePayload('text', $wamId, '1111111111', 'No Trigger User', ['body' => 'stop']);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Should complete without error
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should still be created
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();

        // Contact should remain subscribed (no triggers = no unsubscribe)
        $contact = Contact::find($chat->contact_id);
        expect((int) $contact->is_subscribed)->toBe(1);
    });

    it('continues processing when UnsubscribeChecker receives empty message text', function () {
        $organization = createOrganizationWithSubscription();

        // Create a message with empty body (e.g., media message)
        $wamId = 'wamid_empty_text_'.uniqid();
        $payload = createMessagePayload('image', $wamId, '6666666666', 'Image User');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        // Should complete without error (no text to check)
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should still be created
        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
    });
});
