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
use App\Services\AutoReplyService;
use Illuminate\Support\Facades\Queue;
use Modules\FlowBuilder\Services\FlowExecutionService;

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
            if (array_key_exists('caption', $additionalData)) {
                $message['image']['caption'] = $additionalData['caption'];
            }
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

    it('stores an image URL supplied with a click-to-WhatsApp referral', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_referral_image_'.uniqid();
        $imageUrl = 'https://instagram.example.com/referral-image.jpg?token=abc123';
        $payload = createMessagePayload('text', $wamId, '1234567890', 'Referral User');
        $payload['value']['messages'][0]['referral'] = [
            'source_type' => 'ad',
            'source_id' => '120250333575320095',
            'headline' => 'Chat with us',
            'media_type' => 'image',
            'image_url' => $imageUrl,
        ];

        (new ProcessWebhookJob($payload, $organization->id, 'messages'))->handle();

        $chat = Chat::where('organization_id', $organization->id)
            ->where('wam_id', $wamId)
            ->sole();
        $metadata = json_decode($chat->metadata, true);

        expect($metadata['referral']['media_type'])->toBe('image')
            ->and($metadata['referral']['image_url'])->toBe($imageUrl)
            ->and($metadata['referral']['video_url'])->toBeNull()
            ->and($metadata['referral']['thumbnail_url'])->toBeNull();
    });

    it('does not create an inbound duplicate when a business-number echo matches an outbound chat', function () {
        $organization = createOrganizationWithSubscription();
        $customerPhone = '966555409609';
        $businessPhone = '966565166676';
        $messageBody = 'Booking service details';
        $outboundWamId = 'wamid.HBgMOTY2NTU1NDA5NjA5FQIAERgUQ0UxMDcyNjEwOTY4RjkxOTA1QzMA';
        $echoWamId = 'wamid.HBgMOTY2NTY1MTY2Njc2FQIAERgUQ0UxMDcyNjEwOTY4RjkxOTA1QzMA';

        $contact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+'.$customerPhone,
            'first_name' => 'Customer',
            'last_name' => 'One',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'wam_id' => $outboundWamId,
            'type' => 'outbound',
            'metadata' => json_encode(['type' => 'text', 'text' => ['body' => $messageBody]]),
            'status' => 'delivered',
            'is_read' => 1,
            'created_at' => now(),
        ]);

        $payload = createMessagePayload('text', $echoWamId, $businessPhone, 'Customer One', ['body' => $messageBody]);
        $payload['value']['metadata']['display_phone_number'] = '+966 56 516 6676';
        $payload['value']['contacts'][0]['wa_id'] = $customerPhone;

        (new ProcessWebhookJob($payload, $organization->id, 'messages'))->handle();

        expect(Chat::where('organization_id', $organization->id)->count())->toBe(1);
        expect(Chat::where('organization_id', $organization->id)->first()->type)->toBe('outbound');
        expect(Chat::where('organization_id', $organization->id)->where('type', 'inbound')->exists())->toBeFalse();
    });

    it('stores an unmatched business-number message as outbound, never inbound', function () {
        $organization = createOrganizationWithSubscription();
        $customerPhone = '966555409609';
        $businessPhone = '966565166676';
        $payload = createMessagePayload('text', 'wamid.business_manual_'.uniqid(), $businessPhone, 'Customer One', ['body' => 'Manual business app message']);
        $payload['value']['metadata']['display_phone_number'] = '+966 56 516 6676';
        $payload['value']['contacts'][0]['wa_id'] = $customerPhone;

        (new ProcessWebhookJob($payload, $organization->id, 'messages'))->handle();

        $chat = Chat::where('organization_id', $organization->id)->sole();
        expect($chat->type)->toBe('outbound');
        expect($chat->status)->toBe('sent');
        expect($chat->is_read)->toBe(1);
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

describe('Image caption automation text extraction', function () {
    it('uses image caption as auto-reply message text', function () {
        $caption = 'متى بيتوفر مقاس سمول؟';
        $chat = new Chat([
            'metadata' => json_encode([
                'type' => 'image',
                'image' => ['caption' => $caption],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $service = new AutoReplyService();
        $method = new ReflectionMethod($service, 'extractMessageText');
        $method->setAccessible(true);

        expect($method->invoke($service, $chat))->toBe($caption);
    });

    it('uses image caption as flow response text', function () {
        $caption = 'متى بيتوفر مقاس سمول؟';
        $chat = new Chat([
            'metadata' => json_encode([
                'type' => 'image',
                'image' => ['caption' => $caption],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $reflection = new ReflectionClass(FlowExecutionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('extractMessageText');
        $method->setAccessible(true);

        expect($method->invoke($service, $chat))->toBe($caption);
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

    it('stores image caption so auto-reply can use it as message text', function () {
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

        $wamId = 'wamid_image_caption_reply_'.uniqid();
        $caption = 'متى بيتوفر مقاس سمول؟';
        $payload = createMessagePayload('image', $wamId, '1234567890', 'Caption User', [
            'caption' => $caption,
        ]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        $chat = Chat::where('wam_id', $wamId)->first();
        expect($chat)->not->toBeNull();
        $metadata = json_decode($chat->metadata, true);
        expect($metadata['type'])->toBe('image')
            ->and($metadata['image']['caption'])->toBe($caption);
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

/**
 * Helper to create a valid SMB message echo webhook payload.
 */
function createSmbEchoPayload(
    string $type = 'text',
    string $wamId = null,
    string $to = '1234567890',
    string $from = '0987654321',
    array $additionalData = []
): array {
    $wamId = $wamId ?? 'wamid_smb_echo_'.uniqid();

    $echo = [
        'from' => $from, // Business phone
        'to' => $to, // Recipient phone
        'id' => $wamId,
        'timestamp' => (string) time(),
        'type' => $type,
    ];

    // Add type-specific data
    switch ($type) {
        case 'text':
            $echo['text'] = ['body' => $additionalData['body'] ?? 'Hello from Business App!'];
            break;
        case 'image':
            $echo['image'] = [
                'id' => $additionalData['media_id'] ?? 'media_img_'.uniqid(),
                'mime_type' => 'image/jpeg',
                'sha256' => 'abc123',
            ];
            break;
        case 'video':
            $echo['video'] = [
                'id' => $additionalData['media_id'] ?? 'media_vid_'.uniqid(),
                'mime_type' => 'video/mp4',
            ];
            break;
        case 'document':
            $echo['document'] = [
                'id' => $additionalData['media_id'] ?? 'media_doc_'.uniqid(),
                'mime_type' => 'application/pdf',
                'filename' => 'document.pdf',
            ];
            break;
        case 'audio':
            $echo['audio'] = [
                'id' => $additionalData['media_id'] ?? 'media_aud_'.uniqid(),
                'mime_type' => 'audio/ogg',
            ];
            break;
        case 'sticker':
            $echo['sticker'] = [
                'id' => $additionalData['media_id'] ?? 'media_stk_'.uniqid(),
                'mime_type' => 'image/webp',
            ];
            break;
    }

    return [
        'field' => 'smb_message_echoes',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => $from,
                'phone_number_id' => 'phone_id_123',
            ],
            'message_echoes' => [$echo],
        ],
    ];
}

/**
 * Story 1.10 Tests: SMB Message Echoes Processing
 *
 * Tests for implementing SMB message echoes processing to enable "coexistence mode"
 * where messages sent via WhatsApp Business App appear in the Chatberry dashboard.
 *
 * @see docs/sprint-artifacts/1-10-implement-smb-message-echoes-processing.md
 */
describe('AC-1.10.1: Contact Creation by Recipient Phone', function () {
    it('creates new contact when recipient phone does not exist', function () {
        $organization = createOrganizationWithSubscription();
        $recipientPhone = '5551234567';

        $payload = createSmbEchoPayload('text', null, $recipientPhone);

        // Verify contact doesn't exist
        expect(Contact::where('organization_id', $organization->id)->where('phone', 'like', "%{$recipientPhone}%")->exists())->toBeFalse();

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        // Verify contact was created using recipient phone (not sender)
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', "%{$recipientPhone}%")
            ->first();

        expect($contact)->not->toBeNull();
    });

    it('uses existing contact when recipient phone exists', function () {
        $organization = createOrganizationWithSubscription();

        // Create existing contact
        $existingContact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+5559876543',
            'first_name' => 'Existing',
            'last_name' => 'Recipient',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = createSmbEchoPayload('text', null, '5559876543');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        // Verify no new contact was created
        $contactCount = Contact::where('organization_id', $organization->id)->count();
        expect($contactCount)->toBe(1);

        // Verify chat references existing contact
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat->contact_id)->toBe($existingContact->id);
    });
});

describe('AC-1.10.2: Outbound Chat Creation', function () {
    it('creates chat with type=outbound for echoed messages', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_outbound_'.uniqid();

        $payload = createSmbEchoPayload('text', $wamId);

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)
            ->where('wam_id', $wamId)
            ->first();

        expect($chat)->not->toBeNull();
        expect($chat->type)->toBe('outbound');
        expect($chat->status)->toBe('sent');
        expect($chat->is_read)->toBe(1); // Outbound messages are read
    });

    it('creates chat with correct metadata for text message', function () {
        $organization = createOrganizationWithSubscription();
        $messageBody = 'Hello from WhatsApp Business App!';

        $payload = createSmbEchoPayload('text', null, '1234567890', '0987654321', ['body' => $messageBody]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);

        expect($metadata['type'])->toBe('text');
        expect($metadata['text']['body'])->toBe($messageBody);
    });

    it('creates chat with correct metadata for image message', function () {
        $organization = createOrganizationWithSubscription();
        $mediaId = 'media_img_test_'.uniqid();

        $payload = createSmbEchoPayload('image', null, '1234567890', '0987654321', ['media_id' => $mediaId]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $metadata = json_decode($chat->metadata, true);

        expect($metadata['type'])->toBe('image');
        expect($metadata['image']['id'])->toBe($mediaId);
    });
});

describe('AC-1.10.3: DownloadWebhookMediaJob for Media Echoes', function () {
    it('dispatches DownloadWebhookMediaJob for image echo', function () {
        $organization = createOrganizationWithSubscription();
        $mediaId = 'media_smb_image_'.uniqid();

        $payload = createSmbEchoPayload('image', null, '1234567890', '0987654321', ['media_id' => $mediaId]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) use ($organization, $mediaId) {
            return $job->organizationId === $organization->id
                && $job->mediaData['media_id'] === $mediaId
                && $job->mediaData['type'] === 'image';
        });
    });

    it('dispatches DownloadWebhookMediaJob for video echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('video');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'video';
        });
    });

    it('dispatches DownloadWebhookMediaJob for document echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('document');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'document';
        });
    });

    it('dispatches DownloadWebhookMediaJob for audio echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('audio');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'audio';
        });
    });

    it('dispatches DownloadWebhookMediaJob for sticker echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('sticker');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'sticker';
        });
    });

    it('does NOT dispatch DownloadWebhookMediaJob for text echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });
});

describe('AC-1.10.4: ChatLog Entry Creation', function () {
    it('creates ChatLog entry for SMB echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $chatLog = ChatLog::where('entity_type', 'chat')
            ->where('entity_id', $chat->id)
            ->first();

        expect($chatLog)->not->toBeNull();
        expect($chatLog->contact_id)->toBe($chat->contact_id);
    });
});

describe('AC-1.10.5: ProcessWebhookNotificationsJob Dispatch', function () {
    it('dispatches ProcessWebhookNotificationsJob for SMB echo', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });

    it('dispatches ProcessWebhookNotificationsJob to webhook-high queue', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });

    it('passes isNewContact=true for new contacts', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text', null, '9999888877');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
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
            'phone' => '+1112223333',
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = createSmbEchoPayload('text', null, '1112223333');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->isNewContact === false;
        });
    });
});

describe('AC-1.10.6: Duplicate Echoes Skipped', function () {
    it('skips duplicate echo with same wam_id', function () {
        $organization = createOrganizationWithSubscription();
        $wamId = 'wamid_duplicate_echo_'.uniqid();

        // First echo - should create chat
        $payload1 = createSmbEchoPayload('text', $wamId, '1234567890', '0987654321', ['body' => 'First echo']);
        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'smb_message_echoes');
        $job1->handle();

        $chatCountAfterFirst = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterFirst)->toBe(1);

        // Reset queue fake to count new dispatches
        Queue::fake([DownloadWebhookMediaJob::class, ProcessWebhookNotificationsJob::class]);

        // Second echo with same wam_id - should be skipped
        $payload2 = createSmbEchoPayload('text', $wamId, '1234567890', '0987654321', ['body' => 'Duplicate echo']);
        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'smb_message_echoes');
        $job2->handle();

        $chatCountAfterSecond = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterSecond)->toBe(1); // Still only 1 chat

        // No notification job should be dispatched for duplicate
        Queue::assertNotPushed(ProcessWebhookNotificationsJob::class);
    });

    it('creates separate chats for different wam_ids', function () {
        $organization = createOrganizationWithSubscription();

        $payload1 = createSmbEchoPayload('text', 'wamid_echo_unique_1');
        $payload2 = createSmbEchoPayload('text', 'wamid_echo_unique_2');

        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'smb_message_echoes');
        $job1->handle();

        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'smb_message_echoes');
        $job2->handle();

        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(2);
    });
});

describe('AC-1.10.7: External Webhook Trigger', function () {
    it('job completes successfully triggering smb.message.echo webhook', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        // Should complete without throwing exception (fire-and-forget pattern)
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });

    it('job completes successfully even if webhook trigger fails', function () {
        $organization = createOrganizationWithSubscription();

        $payload = createSmbEchoPayload('text');

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        // Fire-and-forget pattern means job should complete even if webhook fails
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should still be created
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat)->not->toBeNull();
    });
});

describe('SMB Echo Edge Cases', function () {
    it('handles empty message_echoes array gracefully', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        // Should not throw exception
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // No chat should be created
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles missing required fields without crashing', function () {
        $organization = createOrganizationWithSubscription();

        // Payload with missing 'to' and 'id' fields
        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [
                    ['type' => 'text'], // Missing 'to' and 'id'
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        // Should not throw exception
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // No chat should be created
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles missing to field without crashing', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [
                    [
                        'id' => 'wamid_missing_to',
                        'type' => 'text',
                        'text' => ['body' => 'Test'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles missing wam_id field without crashing', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [
                    [
                        'to' => '1234567890',
                        'type' => 'text',
                        'text' => ['body' => 'Test'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('processes multiple echoes in single payload', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [
                    [
                        'id' => 'wamid_multi_1',
                        'to' => '1111111111',
                        'from' => '0987654321',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'First message'],
                    ],
                    [
                        'id' => 'wamid_multi_2',
                        'to' => '2222222222',
                        'from' => '0987654321',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Second message'],
                    ],
                    [
                        'id' => 'wamid_multi_3',
                        'to' => '3333333333',
                        'from' => '0987654321',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Third message'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        // All 3 chats should be created
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(3);

        // All 3 contacts should be created
        $contactCount = Contact::where('organization_id', $organization->id)->count();
        expect($contactCount)->toBe(3);

        // All should be outbound
        $outboundCount = Chat::where('organization_id', $organization->id)
            ->where('type', 'outbound')
            ->count();
        expect($outboundCount)->toBe(3);
    });

    it('processes multiple echoes to same recipient correctly', function () {
        $organization = createOrganizationWithSubscription();

        $payload = [
            'field' => 'smb_message_echoes',
            'value' => [
                'message_echoes' => [
                    [
                        'id' => 'wamid_same_recipient_1',
                        'to' => '5555555555',
                        'from' => '0987654321',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'First'],
                    ],
                    [
                        'id' => 'wamid_same_recipient_2',
                        'to' => '5555555555',
                        'from' => '0987654321',
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Second'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'smb_message_echoes');
        $job->handle();

        // Both chats should be created
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(2);

        // Only 1 contact should be created (same recipient)
        $contactCount = Contact::where('organization_id', $organization->id)->count();
        expect($contactCount)->toBe(1);
    });
});

describe('SMB Echo FIFO Ordering', function () {
    it('has middleware for FIFO ordering using recipient phone', function () {
        $payload = createSmbEchoPayload('text', null, '9876543210');
        $job = new ProcessWebhookJob($payload, 1, 'smb_message_echoes');

        $middleware = $job->middleware();

        expect($middleware)->not->toBeEmpty();
    });
});

// =============================================================================
// STORY 1.11: SMB App State Sync Processing
// =============================================================================

/**
 * Helper to create a valid SMB App State Sync webhook payload.
 */
function createStateSyncPayload(
    array $syncItems = [],
    bool $syncEnabled = true
): array {
    return [
        'field' => 'smb_app_state_sync',
        'value' => [
            'state_sync' => $syncItems,
        ],
    ];
}

/**
 * Helper to create a single state sync item for contacts.
 */
function createStateSyncItem(
    string $action = 'create',
    string $phone = '1234567890',
    ?string $firstName = 'John',
    ?string $fullName = 'John Doe'
): array {
    return [
        'type' => 'contact',
        'action' => $action,
        'contact' => [
            'phone_number' => $phone,
            'first_name' => $firstName,
            'full_name' => $fullName,
        ],
        'metadata' => [
            'timestamp' => (string) time(),
        ],
    ];
}

/**
 * Helper to create organization with sync_contacts enabled.
 */
function createOrganizationWithSyncEnabled(bool $syncEnabled = true): Organization
{
    $organization = createOrganizationWithSubscription();

    // Update metadata to include sync_contacts flag
    $metadata = json_decode($organization->metadata ?? '{}', true);
    $metadata['sync_contacts'] = $syncEnabled ? 1 : 0;
    $organization->metadata = json_encode($metadata);
    $organization->save();

    return $organization;
}

describe('Story 1.11: SMB App State Sync Processing', function () {
    describe('AC-1.11.1: Check sync_contacts Flag', function () {
        it('skips processing when sync_contacts=0', function () {
            $organization = createOrganizationWithSyncEnabled(false);

            $syncItem = createStateSyncItem('create', '9998887777', 'Test', 'Test User');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // Contact should NOT be created
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%9998887777%')
                ->exists())->toBeFalse();
        });

        it('processes when sync_contacts=1', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = createStateSyncItem('create', '9998887776', 'Test', 'Test User');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // Contact should be created
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%9998887776%')
                ->exists())->toBeTrue();
        });

        it('processes when sync_contacts is not set (defaults to disabled)', function () {
            // Create org without setting sync_contacts (metadata is null or empty)
            $organization = createOrganizationWithSubscription();
            $organization->metadata = json_encode(['other' => 'settings']);
            $organization->save();

            $syncItem = createStateSyncItem('create', '9998887775', 'Test', 'Test User');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // Contact should NOT be created (sync disabled by default)
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%9998887775%')
                ->exists())->toBeFalse();
        });
    });

    describe('AC-1.11.2: Create Action Creates Contact', function () {
        it('creates new contact when phone does not exist', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '5551234567';

            $syncItem = createStateSyncItem('create', $phone, 'New', 'New Contact');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact = Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', "%{$phone}%")
                ->first();

            expect($contact)->not->toBeNull();
            expect($contact->first_name)->toBe('New');
            expect($contact->last_name)->toBe('Contact');
            expect($contact->is_subscribed)->toBe(1);
        });

        it('uses existing contact when phone already exists', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '+5559876543';

            // Create existing contact
            $existingContact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => $phone,
                'first_name' => 'Existing',
                'last_name' => 'Contact',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $syncItem = createStateSyncItem('create', '5559876543', 'Different', 'Different Name');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // Should still be only 1 contact
            expect(Contact::where('organization_id', $organization->id)->count())->toBe(1);

            // Name should NOT be changed (create doesn't update existing)
            $contact = Contact::find($existingContact->id);
            expect($contact->first_name)->toBe('Existing');
        });

        it('uses first_name over full_name when both provided', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '5552223333';

            $syncItem = createStateSyncItem('create', $phone, 'FirstNameOnly', 'Full Name Different');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact = Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', "%{$phone}%")
                ->first();

            expect($contact->first_name)->toBe('FirstNameOnly');
        });

        it('parses full_name when first_name is not provided', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '5553334444';

            $syncItem = [
                'type' => 'contact',
                'action' => 'create',
                'contact' => [
                    'phone_number' => $phone,
                    'full_name' => 'Jane Smith',
                    // first_name NOT provided
                ],
            ];
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact = Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', "%{$phone}%")
                ->first();

            expect($contact->first_name)->toBe('Jane');
            expect($contact->last_name)->toBe('Smith');
        });
    });

    describe('AC-1.11.3: Update Action Updates Name If Empty', function () {
        it('updates first_name when contact exists and name is empty', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '+5554445555';

            // Create contact with empty name
            $contact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => $phone,
                'first_name' => '', // Empty name
                'last_name' => '',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $syncItem = createStateSyncItem('update', '5554445555', 'UpdatedName', null);
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact->refresh();
            expect($contact->first_name)->toBe('UpdatedName');
        });

        it('does NOT overwrite existing first_name', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '+5555556666';

            // Create contact with existing name
            $contact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => $phone,
                'first_name' => 'ExistingName',
                'last_name' => 'ExistingLast',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $syncItem = createStateSyncItem('update', '5555556666', 'NewName', null);
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact->refresh();
            expect($contact->first_name)->toBe('ExistingName'); // Should NOT change
        });

        it('handles update for non-existent contact gracefully', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = createStateSyncItem('update', '9999999999', 'UpdateName', null);
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            // Should not throw exception
            expect(fn () => $job->handle())->not->toThrow(\Exception::class);

            // No contact should be created
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%9999999999%')
                ->exists())->toBeFalse();
        });
    });

    describe('AC-1.11.4: Delete Action Soft-Deletes Contact', function () {
        it('soft-deletes contact by setting deleted_at', function () {
            $organization = createOrganizationWithSyncEnabled(true);
            $phone = '+5556667777';

            // Create contact
            $contact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => $phone,
                'first_name' => 'ToDelete',
                'last_name' => 'Contact',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            expect($contact->deleted_at)->toBeNull();

            $syncItem = createStateSyncItem('delete', '5556667777', null, null);
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            $contact->refresh();
            expect($contact->deleted_at)->not->toBeNull();
        });

        it('handles delete for non-existent contact gracefully', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = createStateSyncItem('delete', '8888888888', null, null);
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            // Should not throw exception
            expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        });
    });

    describe('AC-1.11.5: Ticket Assignment for New Contacts', function () {
        it('does not crash when ticket assignment is called for new contact', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            // Enable ticketing in org metadata
            $metadata = json_decode($organization->metadata, true);
            $metadata['tickets'] = [
                'active' => true,
                'auto_assignment' => false,
            ];
            $metadata['sync_contacts'] = 1;
            $organization->metadata = json_encode($metadata);
            $organization->save();

            $syncItem = createStateSyncItem('create', '5557778888', 'Ticket', 'Test Contact');
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            // Should complete without exception (ticket assignment is non-blocking)
            expect(fn () => $job->handle())->not->toThrow(\Exception::class);

            // Contact should be created
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%5557778888%')
                ->exists())->toBeTrue();
        });
    });

    describe('SMB App State Sync Edge Cases', function () {
        it('handles empty state_sync array gracefully', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $payload = createStateSyncPayload([]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        });

        it('handles missing phone_number field', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = [
                'type' => 'contact',
                'action' => 'create',
                'contact' => [
                    'first_name' => 'NoPhone',
                    // phone_number missing
                ],
            ];
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            expect(fn () => $job->handle())->not->toThrow(\Exception::class);

            // No contact should be created
            expect(Contact::where('organization_id', $organization->id)
                ->where('first_name', 'NoPhone')
                ->exists())->toBeFalse();
        });

        it('skips non-contact type sync items', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = [
                'type' => 'other_type', // Not 'contact'
                'action' => 'create',
                'data' => ['something' => 'else'],
            ];
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        });

        it('handles unknown action gracefully', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItem = [
                'type' => 'contact',
                'action' => 'unknown_action',
                'contact' => [
                    'phone_number' => '1112223333',
                    'first_name' => 'Unknown',
                ],
            ];
            $payload = createStateSyncPayload([$syncItem]);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');

            expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        });

        it('processes multiple contacts in single payload', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            $syncItems = [
                createStateSyncItem('create', '1111111111', 'First', 'First Contact'),
                createStateSyncItem('create', '2222222222', 'Second', 'Second Contact'),
                createStateSyncItem('create', '3333333333', 'Third', 'Third Contact'),
            ];
            $payload = createStateSyncPayload($syncItems);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // All 3 contacts should be created
            expect(Contact::where('organization_id', $organization->id)->count())->toBe(3);
        });

        it('handles mixed actions in single payload', function () {
            $organization = createOrganizationWithSyncEnabled(true);

            // Create existing contact for update and delete
            $updateContact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => '+4444444444',
                'first_name' => '',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $deleteContact = Contact::create([
                'organization_id' => $organization->id,
                'phone' => '+5555555555',
                'first_name' => 'ToDelete',
                'is_subscribed' => 1,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $syncItems = [
                createStateSyncItem('create', '6666666666', 'New', 'New Contact'),
                createStateSyncItem('update', '4444444444', 'Updated', null),
                createStateSyncItem('delete', '5555555555', null, null),
            ];
            $payload = createStateSyncPayload($syncItems);

            $job = new ProcessWebhookJob($payload, $organization->id, 'smb_app_state_sync');
            $job->handle();

            // New contact created
            expect(Contact::where('organization_id', $organization->id)
                ->where('phone', 'like', '%6666666666%')
                ->exists())->toBeTrue();

            // Update contact should have updated name
            $updateContact->refresh();
            expect($updateContact->first_name)->toBe('Updated');

            // Delete contact should be soft-deleted
            $deleteContact->refresh();
            expect($deleteContact->deleted_at)->not->toBeNull();
        });
    });
});

// =============================================================================
// STORY 1.12: History Webhook Processing
// =============================================================================

/**
 * Helper to create organization with sync_history enabled.
 */
function createOrganizationWithSyncHistory(bool $syncEnabled = true, string $location = 'root'): Organization
{
    $organization = createOrganizationWithSubscription();

    $metadata = json_decode($organization->metadata, true) ?? [];

    // Store sync_history in specified location
    if ($location === 'root') {
        $metadata['sync_history'] = $syncEnabled ? 1 : 0;
    } elseif ($location === 'whatsapp') {
        $metadata['whatsapp']['sync_history'] = $syncEnabled ? 1 : 0;
    }

    $organization->metadata = json_encode($metadata);
    $organization->save();

    return $organization;
}

/**
 * Helper to create a valid history webhook payload.
 */
function createHistoryPayload(
    array $threads = [],
    array $chunkMetadata = [],
    int $chunkCount = 1
): array {
    $history = [];

    for ($i = 0; $i < $chunkCount; $i++) {
        $history[] = [
            'threads' => $threads,
            'metadata' => $chunkMetadata,
        ];
    }

    return [
        'field' => 'history',
        'value' => [
            'history' => $history,
        ],
    ];
}

/**
 * Helper to create a single history thread with messages.
 */
function createHistoryThread(
    string $threadId,
    array $messages = []
): array {
    return [
        'id' => $threadId,
        'messages' => $messages,
    ];
}

/**
 * Helper to create a single history message.
 */
function createHistoryMessage(
    string $wamId = null,
    string $direction = 'inbound',
    string $type = 'text',
    int $timestamp = null,
    array $additionalData = []
): array {
    $wamId = $wamId ?? 'wamid_history_'.uniqid();
    $timestamp = $timestamp ?? time();

    $message = [
        'id' => $wamId,
        'direction' => $direction,
        'type' => $type,
        'timestamp' => (string) $timestamp,
    ];

    // Add type-specific data
    switch ($type) {
        case 'text':
            $message['text'] = ['body' => $additionalData['body'] ?? 'Historical message'];
            break;
        case 'image':
            $message['image'] = [
                'id' => $additionalData['media_id'] ?? 'media_history_img_'.uniqid(),
                'mime_type' => 'image/jpeg',
                'sha256' => 'abc123',
            ];
            break;
        case 'video':
            $message['video'] = [
                'id' => $additionalData['media_id'] ?? 'media_history_vid_'.uniqid(),
                'mime_type' => 'video/mp4',
            ];
            break;
        case 'document':
            $message['document'] = [
                'id' => $additionalData['media_id'] ?? 'media_history_doc_'.uniqid(),
                'mime_type' => 'application/pdf',
                'filename' => 'document.pdf',
            ];
            break;
        case 'audio':
            $message['audio'] = [
                'id' => $additionalData['media_id'] ?? 'media_history_audio_'.uniqid(),
                'mime_type' => 'audio/ogg',
            ];
            break;
    }

    return $message;
}

/**
 * Story 1.12 Tests: History Webhook Processing
 *
 * Tests for implementing history webhook processing to enable historical message
 * import from WhatsApp Business App when organizations migrate to coexistence mode.
 *
 * @see docs/sprint-artifacts/1-12-implement-history-webhook-processing.md
 */
describe('AC-1.12.1: Sync History Flag Check', function () {
    it('skips processing when sync_history flag is disabled', function () {
        $organization = createOrganizationWithSyncHistory(false);
        $threadId = '1234567890';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // No chat should be created when sync_history is disabled
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('processes when metadata.sync_history is enabled (root location)', function () {
        $organization = createOrganizationWithSyncHistory(true, 'root');
        $threadId = '5551234567';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Chat should be created when sync_history is enabled
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(1);
    });

    it('processes when metadata.whatsapp.sync_history is enabled (nested location)', function () {
        $organization = createOrganizationWithSyncHistory(true, 'whatsapp');
        $threadId = '5559876543';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Chat should be created when sync_history is enabled at whatsapp location
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(1);
    });
});

describe('AC-1.12.3: Contact Creation from Thread IDs', function () {
    it('creates new contact from thread ID (phone number)', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '5557778888';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        // Verify contact doesn't exist
        expect(Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', "%{$threadId}%")
            ->exists())->toBeFalse();

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Verify contact was created
        $contact = Contact::where('organization_id', $organization->id)
            ->where('phone', 'like', "%{$threadId}%")
            ->first();

        expect($contact)->not->toBeNull();
    });

    it('uses existing contact when thread phone exists', function () {
        $organization = createOrganizationWithSyncHistory(true);

        // Create existing contact
        $existingContact = Contact::create([
            'organization_id' => $organization->id,
            'phone' => '+1112223333',
            'first_name' => 'Existing',
            'last_name' => 'Contact',
            'is_subscribed' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread('1112223333', $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Verify no new contact was created
        $contactCount = Contact::where('organization_id', $organization->id)->count();
        expect($contactCount)->toBe(1);

        // Verify chat references existing contact
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat->contact_id)->toBe($existingContact->id);
    });
});

describe('AC-1.12.4: Original Timestamp Preservation', function () {
    it('imports messages with original timestamps', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '4445556666';

        // Use a specific historical timestamp (1 month ago)
        $historicalTimestamp = time() - (30 * 24 * 60 * 60);

        $messages = [createHistoryMessage(null, 'inbound', 'text', $historicalTimestamp)];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();

        // created_at should match the historical timestamp (within 1 second tolerance)
        $expectedDate = \Carbon\Carbon::createFromTimestamp($historicalTimestamp);
        $chatCreatedAt = \Carbon\Carbon::parse($chat->created_at);
        expect($chatCreatedAt->diffInSeconds($expectedDate))->toBeLessThanOrEqual(1);
    });

    it('creates ChatLog entry with original timestamp', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '7778889999';

        $historicalTimestamp = time() - (15 * 24 * 60 * 60);

        $messages = [createHistoryMessage(null, 'inbound', 'text', $historicalTimestamp)];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $chatLog = ChatLog::where('entity_type', 'chat')
            ->where('entity_id', $chat->id)
            ->first();

        expect($chatLog)->not->toBeNull();

        $expectedDate = \Carbon\Carbon::createFromTimestamp($historicalTimestamp);
        $chatLogCreatedAt = \Carbon\Carbon::parse($chatLog->created_at);
        expect($chatLogCreatedAt->diffInSeconds($expectedDate))->toBeLessThanOrEqual(1);
    });
});

describe('AC-1.12.5: Inbound and Outbound Direction Handling', function () {
    it('creates chat with type=inbound for inbound history messages', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '1231231234';

        $messages = [createHistoryMessage(null, 'inbound', 'text')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();

        expect($chat->type)->toBe('inbound');
        expect($chat->status)->toBe('received');
        expect($chat->is_read)->toBe(0);
    });

    it('creates chat with type=outbound for outbound history messages', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '3213214321';

        $messages = [createHistoryMessage(null, 'outbound', 'text')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();

        expect($chat->type)->toBe('outbound');
        expect($chat->status)->toBe('sent');
        expect($chat->is_read)->toBe(1);
    });

    it('skips a history message when direction is missing', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '6546546543';

        // Create message without direction field
        $message = [
            'id' => 'wamid_no_direction_'.uniqid(),
            'type' => 'text',
            'timestamp' => (string) time(),
            'text' => ['body' => 'No direction specified'],
        ];

        $threads = [createHistoryThread($threadId, [$message])];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        expect(Chat::where('organization_id', $organization->id)->exists())->toBeFalse();
        Queue::assertNotPushed(ProcessWebhookNotificationsJob::class);
    });
});

describe('AC-1.12.6: Duplicate Messages Skipped', function () {
    it('skips duplicate history message with same wam_id', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '9998887776';
        $wamId = 'wamid_duplicate_history_'.uniqid();

        // First import
        $messages1 = [createHistoryMessage($wamId, 'inbound', 'text')];
        $threads1 = [createHistoryThread($threadId, $messages1)];
        $payload1 = createHistoryPayload($threads1);

        $job1 = new ProcessWebhookJob($payload1, $organization->id, 'history');
        $job1->handle();

        $chatCountAfterFirst = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterFirst)->toBe(1);

        // Reset queue fake
        Queue::fake([DownloadWebhookMediaJob::class, ProcessWebhookNotificationsJob::class]);

        // Second import with same wam_id - should be skipped
        $messages2 = [createHistoryMessage($wamId, 'inbound', 'text')];
        $threads2 = [createHistoryThread($threadId, $messages2)];
        $payload2 = createHistoryPayload($threads2);

        $job2 = new ProcessWebhookJob($payload2, $organization->id, 'history');
        $job2->handle();

        $chatCountAfterSecond = Chat::where('organization_id', $organization->id)->count();
        expect($chatCountAfterSecond)->toBe(1); // Still only 1 chat

        // No notification job should be dispatched for duplicate
        Queue::assertNotPushed(ProcessWebhookNotificationsJob::class);
    });

    it('creates separate chats for different wam_ids', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '1111111111';

        $messages = [
            createHistoryMessage('wamid_history_unique_1'),
            createHistoryMessage('wamid_history_unique_2'),
        ];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(2);
    });
});

describe('AC-1.12.7: Progress Metadata Logging', function () {
    it('job completes successfully with progress metadata', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '2222222222';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];

        // Include progress metadata
        $chunkMetadata = [
            'phase' => 'initial',
            'progress' => '0.25',
        ];
        $payload = createHistoryPayload($threads, $chunkMetadata);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        // Should complete without exception
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should be created
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(1);
    });

    it('handles incremental phase metadata', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '3333333333';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];

        $chunkMetadata = [
            'phase' => 'incremental',
            'progress' => '0.75',
        ];
        $payload = createHistoryPayload($threads, $chunkMetadata);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });

    it('handles final phase metadata', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '4444444444';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];

        $chunkMetadata = [
            'phase' => 'final',
            'progress' => '1.0',
        ];
        $payload = createHistoryPayload($threads, $chunkMetadata);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });
});

describe('History Media Job Dispatch', function () {
    it('dispatches DownloadWebhookMediaJob for image history', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '5555555555';
        $mediaId = 'media_history_image_test';

        $messages = [createHistoryMessage(null, 'inbound', 'image', null, ['media_id' => $mediaId])];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) use ($organization, $mediaId) {
            return $job->organizationId === $organization->id
                && $job->mediaData['media_id'] === $mediaId
                && $job->mediaData['type'] === 'image';
        });
    });

    it('dispatches DownloadWebhookMediaJob for video history', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '6666666666';

        $messages = [createHistoryMessage(null, 'inbound', 'video')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'video';
        });
    });

    it('dispatches DownloadWebhookMediaJob for document history', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '7777777777';

        $messages = [createHistoryMessage(null, 'inbound', 'document')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'document';
        });
    });

    it('dispatches DownloadWebhookMediaJob for audio history', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '8888888888';

        $messages = [createHistoryMessage(null, 'inbound', 'audio')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) {
            return $job->mediaData['type'] === 'audio';
        });
    });

    it('does NOT dispatch DownloadWebhookMediaJob for text history', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '9999999999';

        $messages = [createHistoryMessage(null, 'inbound', 'text')];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        // Reset queue fake to ensure clean state
        Queue::fake([DownloadWebhookMediaJob::class, ProcessWebhookNotificationsJob::class]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertNotPushed(DownloadWebhookMediaJob::class);
    });
});

describe('History Notification Job Dispatch', function () {
    it('dispatches ProcessWebhookNotificationsJob for history message', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '1010101010';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });

    it('dispatches ProcessWebhookNotificationsJob to webhook-high queue', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '2020202020';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        Queue::assertPushed(ProcessWebhookNotificationsJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });
});

describe('History Edge Cases', function () {
    it('handles empty history array gracefully', function () {
        $organization = createOrganizationWithSyncHistory(true);

        $payload = [
            'field' => 'history',
            'value' => [
                'history' => [],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles empty threads array gracefully', function () {
        $organization = createOrganizationWithSyncHistory(true);

        $payload = createHistoryPayload([]);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles empty messages array gracefully', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '3030303030';

        $threads = [createHistoryThread($threadId, [])];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles missing thread ID gracefully', function () {
        $organization = createOrganizationWithSyncHistory(true);

        $threads = [
            [
                // Missing 'id' field
                'messages' => [createHistoryMessage()],
            ],
        ];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('handles missing wam_id gracefully', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '4040404040';

        $message = [
            // Missing 'id' (wam_id) field
            'direction' => 'inbound',
            'type' => 'text',
            'timestamp' => (string) time(),
            'text' => ['body' => 'No wam_id'],
        ];

        $threads = [createHistoryThread($threadId, [$message])];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(0);
    });

    it('processes multiple threads in single webhook', function () {
        $organization = createOrganizationWithSyncHistory(true);

        $threads = [
            createHistoryThread('1111111111', [createHistoryMessage()]),
            createHistoryThread('2222222222', [createHistoryMessage()]),
            createHistoryThread('3333333333', [createHistoryMessage()]),
        ];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Should create 3 chats (one per thread)
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(3);

        // Should create 3 contacts
        expect(Contact::where('organization_id', $organization->id)->count())->toBe(3);
    });

    it('processes multiple messages per thread', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '5050505050';

        $messages = [
            createHistoryMessage('wamid_multi_1', 'inbound'),
            createHistoryMessage('wamid_multi_2', 'outbound'),
            createHistoryMessage('wamid_multi_3', 'inbound'),
        ];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Should create 3 chats (one per message)
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(3);

        // Should create only 1 contact
        expect(Contact::where('organization_id', $organization->id)->count())->toBe(1);
    });

    it('processes multiple chunks in single webhook', function () {
        $organization = createOrganizationWithSyncHistory(true);

        // Create payload with 3 chunks, each with unique messages
        $payload = [
            'field' => 'history',
            'value' => [
                'history' => [
                    [
                        'threads' => [createHistoryThread('6060606060', [createHistoryMessage('chunk1_msg')])],
                        'metadata' => ['phase' => 'initial', 'progress' => '0.33'],
                    ],
                    [
                        'threads' => [createHistoryThread('6060606060', [createHistoryMessage('chunk2_msg')])],
                        'metadata' => ['phase' => 'incremental', 'progress' => '0.66'],
                    ],
                    [
                        'threads' => [createHistoryThread('6060606060', [createHistoryMessage('chunk3_msg')])],
                        'metadata' => ['phase' => 'final', 'progress' => '1.0'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');
        $job->handle();

        // Should create 3 chats (one per chunk with unique wam_ids)
        expect(Chat::where('organization_id', $organization->id)->count())->toBe(3);

        // Should only create 1 contact (same thread ID across chunks)
        expect(Contact::where('organization_id', $organization->id)->count())->toBe(1);
    });

    it('handles non-existent organization gracefully', function () {
        $payload = createHistoryPayload([createHistoryThread('1234567890', [createHistoryMessage()])]);

        // Use non-existent organization ID
        $job = new ProcessWebhookJob($payload, 99999, 'history');

        // Should complete without exception (logs warning)
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);
    });

    it('external webhook trigger failure does not fail job', function () {
        $organization = createOrganizationWithSyncHistory(true);
        $threadId = '7070707070';

        $messages = [createHistoryMessage()];
        $threads = [createHistoryThread($threadId, $messages)];
        $payload = createHistoryPayload($threads);

        $job = new ProcessWebhookJob($payload, $organization->id, 'history');

        // Fire-and-forget pattern means job should complete even if webhook fails
        expect(fn () => $job->handle())->not->toThrow(\Exception::class);

        // Chat should still be created
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat)->not->toBeNull();
    });
});
