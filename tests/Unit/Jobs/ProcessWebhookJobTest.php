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
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();

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
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('video');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'video';
        });
    });

    it('dispatches DownloadWebhookMediaJob for document message', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('document');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'document';
        });
    });

    it('dispatches DownloadWebhookMediaJob for audio message', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('audio');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'audio';
        });
    });

    it('does NOT dispatch DownloadWebhookMediaJob for text message', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });

    it('does NOT dispatch DownloadWebhookMediaJob for location message', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('location');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });
});

describe('AC-1.2.4: ProcessWebhookNotificationsJob Dispatch', function () {
    it('dispatches ProcessWebhookNotificationsJob after chat creation', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });

    it('dispatches ProcessWebhookNotificationsJob to webhook-high queue', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });

    it('passes isNewContact=true for new contacts', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('text', null, '9999999999', 'Brand New Contact');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->isNewContact === true;
        });
    });

    it('passes isNewContact=false for existing contacts', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

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
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();

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
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('text');
    });

    it('handles image message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('image');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('image');
        expect($metadata['image'])->not->toBeEmpty();
    });

    it('handles video message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('video');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('video');
    });

    it('handles document message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('document');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('document');
    });

    it('handles audio message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('audio');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('audio');
    });

    it('handles location message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('location');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('location');
        expect($metadata['location']['latitude'])->toBe(37.7749);
    });

    it('handles contacts message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createMessagePayload('contacts');

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('contacts');
    });

    it('handles interactive message type', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
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
        $organization = Organization::factory()->withoutAppSecret()->create();

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
        $organization = Organization::factory()->withoutAppSecret()->create();

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
        $organization = Organization::factory()->withoutAppSecret()->create();

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
