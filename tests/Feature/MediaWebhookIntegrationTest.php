<?php

/**
 * MediaWebhookIntegrationTest - Story 1.3 Integration Tests
 *
 * Tests for end-to-end media webhook flow:
 * - ProcessWebhookJob dispatching DownloadWebhookMediaJob
 * - Media appearing in chat after job completes
 * - Full flow from webhook to stored media
 *
 * @see docs/sprint-artifacts/1-3-create-downloadwebhookmediajob-for-async-media-handling.md
 */

use App\Jobs\Webhook\DownloadWebhookMediaJob;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Jobs\Webhook\ProcessWebhookNotificationsJob;
use App\Models\Chat;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('s3');

    Queue::fake([
        ProcessWebhookNotificationsJob::class,
    ]);

    // Ensure storage_system setting exists (does not delete existing data)
    Setting::firstOrCreate(
        ['key' => 'storage_system'],
        ['value' => 'local']
    );
});

/**
 * Helper to create an organization with WhatsApp credentials and valid subscription.
 * Subscription is required for ProcessWebhookJob to process inbound messages.
 */
function createMediaTestOrganization(): Organization
{
    $organization = Organization::factory()->create([
        'metadata' => json_encode([
            'whatsapp' => [
                'access_token' => 'test_access_token_'.uniqid(),
                'phone_number_id' => 'phone_123',
            ],
            'storage' => 'local',
        ]),
    ]);

    // Create a subscription plan that allows receiving messages
    $plan = SubscriptionPlan::firstOrCreate(
        ['name' => 'Test Plan'],
        [
            'price' => 0,
            'period' => 'monthly',
            'metadata' => json_encode(['receive_messages_after_expiration' => true]),
        ]
    );

    // Create subscription for the organization
    Subscription::create([
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'start_date' => now(),
        'valid_until' => now()->addYear(),
    ]);

    return $organization;
}

/**
 * Helper to create a webhook payload for media message.
 */
function createMediaWebhookPayload(string $mediaType, string $mediaId, string $from = '1234567890'): array
{
    $message = [
        'from' => $from,
        'id' => 'wamid_test_'.uniqid(),
        'timestamp' => (string) time(),
        'type' => $mediaType,
    ];

    $mediaConfig = [
        'image' => ['mime_type' => 'image/jpeg', 'id' => $mediaId, 'sha256' => 'abc123'],
        'video' => ['mime_type' => 'video/mp4', 'id' => $mediaId],
        'audio' => ['mime_type' => 'audio/ogg', 'id' => $mediaId],
        'document' => ['mime_type' => 'application/pdf', 'id' => $mediaId, 'filename' => 'document.pdf'],
        'sticker' => ['mime_type' => 'image/webp', 'id' => $mediaId],
    ];

    $message[$mediaType] = $mediaConfig[$mediaType];

    return [
        'field' => 'messages',
        'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => [
                'display_phone_number' => '0987654321',
                'phone_number_id' => 'phone_id_123',
            ],
            'contacts' => [
                ['profile' => ['name' => 'Test User'], 'wa_id' => $from],
            ],
            'messages' => [$message],
        ],
    ];
}

/**
 * Mock WhatsApp Graph API for media operations.
 */
function mockWhatsAppMediaApiForIntegration(string $mediaId): void
{
    $mediaUrl = 'https://cdn.whatsapp.net/media/test_'.uniqid().'.jpg';

    Http::fake([
        "graph.facebook.com/v18.0/{$mediaId}" => Http::response([
            'url' => $mediaUrl,
            'mime_type' => 'image/jpeg',
            'sha256' => 'abc123',
            'file_size' => 12345,
        ]),
        $mediaUrl => Http::response('fake_binary_content_for_testing', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);
}

describe('End-to-End Media Webhook Flow', function () {
    it('ProcessWebhookJob dispatches DownloadWebhookMediaJob for image message', function () {
        Queue::fake([
            DownloadWebhookMediaJob::class,
            ProcessWebhookNotificationsJob::class,
        ]);

        $organization = createMediaTestOrganization();
        $mediaId = 'media_image_'.uniqid();
        $payload = createMediaWebhookPayload('image', $mediaId);

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();

        Queue::assertPushed(DownloadWebhookMediaJob::class, function ($job) use ($organization, $mediaId) {
            return $job->organizationId === $organization->id
                && $job->mediaData['media_id'] === $mediaId
                && $job->mediaData['type'] === 'image';
        });
    });

    it('complete media flow creates Chat with ChatMedia reference', function () {
        // Fake DownloadWebhookMediaJob dispatch to prevent duplicate execution
        Queue::fake([
            DownloadWebhookMediaJob::class,
            ProcessWebhookNotificationsJob::class,
        ]);

        $initialMediaCount = ChatMedia::count();

        $organization = createMediaTestOrganization();
        $mediaId = 'media_complete_flow_'.uniqid();
        $payload = createMediaWebhookPayload('image', $mediaId);

        mockWhatsAppMediaApiForIntegration($mediaId);

        // Step 1: ProcessWebhookJob creates contact and chat, dispatches media job (faked)
        $processJob = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $processJob->handle();

        // Verify chat was created
        $chat = Chat::where('organization_id', $organization->id)->first();
        expect($chat)->not->toBeNull();

        // Get the contact
        $contact = Contact::where('organization_id', $organization->id)->first();
        expect($contact)->not->toBeNull();

        // Verify DownloadWebhookMediaJob was dispatched
        Queue::assertPushed(DownloadWebhookMediaJob::class);

        // Step 2: Execute DownloadWebhookMediaJob manually to test full flow
        $mediaData = [
            'type' => 'image',
            'media_id' => $mediaId,
            'mime_type' => 'image/jpeg',
            'sha256' => 'abc123',
            'filename' => null,
            'caption' => null,
        ];

        $downloadJob = new DownloadWebhookMediaJob(
            $chat->id,
            $contact->id,
            $organization->id,
            $mediaData
        );
        $downloadJob->handle();

        // Verify ChatMedia was created (one new record)
        expect(ChatMedia::count() - $initialMediaCount)->toBe(1);

        // Query the ChatMedia linked to this specific chat
        $chat->refresh();
        expect($chat->media_id)->not->toBeNull();
        $chatMedia = ChatMedia::find($chat->media_id);
        expect($chatMedia)->not->toBeNull();
        expect($chatMedia->type)->toContain('image'); // May be 'image' or 'image/jpeg'
        expect($chatMedia->location)->toBe('local');
    });

    it('handles document with original filename', function () {
        $organization = createMediaTestOrganization();
        $mediaId = 'media_doc_'.uniqid();
        $payload = createMediaWebhookPayload('document', $mediaId);

        $mediaUrl = 'https://cdn.whatsapp.net/doc/test.pdf';
        Http::fake([
            "graph.facebook.com/v18.0/{$mediaId}" => Http::response([
                'url' => $mediaUrl,
                'mime_type' => 'application/pdf',
            ]),
            $mediaUrl => Http::response('%PDF-1.4 fake pdf content', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        // Create chat first via ProcessWebhookJob
        $processJob = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $processJob->handle();

        $chat = Chat::where('organization_id', $organization->id)->first();
        $contact = Contact::where('organization_id', $organization->id)->first();

        // Execute media download
        $mediaData = [
            'type' => 'document',
            'media_id' => $mediaId,
            'mime_type' => 'application/pdf',
            'sha256' => null,
            'filename' => 'document.pdf',
            'caption' => null,
        ];

        $downloadJob = new DownloadWebhookMediaJob(
            $chat->id,
            $contact->id,
            $organization->id,
            $mediaData
        );
        $downloadJob->handle();

        // Query the ChatMedia linked to this specific chat
        $chat->refresh();
        $chatMedia = ChatMedia::find($chat->media_id);
        expect($chatMedia)->not->toBeNull();
        expect($chatMedia->name)->not->toBeEmpty();
        expect($chatMedia->type)->toContain('document'); // May be 'document' or 'application/pdf'
    });
});

describe('Media Processing Performance', function () {
    it('processes media within acceptable time', function () {
        $organization = createMediaTestOrganization();
        $contact = Contact::factory()->create(['organization_id' => $organization->id]);
        $chat = Chat::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
        ]);

        $initialMediaCount = ChatMedia::count();

        $mediaId = 'media_perf_'.uniqid();

        Http::fake([
            "graph.facebook.com/v18.0/{$mediaId}" => Http::response([
                'url' => 'https://cdn.whatsapp.net/media/test.jpg',
                'mime_type' => 'image/jpeg',
            ]),
            'https://cdn.whatsapp.net/media/test.jpg' => Http::response(
                str_repeat('x', 100000), // 100KB fake content
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $mediaData = [
            'type' => 'image',
            'media_id' => $mediaId,
            'mime_type' => 'image/jpeg',
            'sha256' => null,
            'filename' => null,
            'caption' => null,
        ];

        $startTime = microtime(true);

        $job = new DownloadWebhookMediaJob(
            $chat->id,
            $contact->id,
            $organization->id,
            $mediaData
        );
        $job->handle();

        $duration = (microtime(true) - $startTime) * 1000; // in ms

        // Should complete within 10 seconds (10000ms) as per AC
        expect($duration)->toBeLessThan(10000);

        // Verify media was created (one new record)
        expect(ChatMedia::count() - $initialMediaCount)->toBe(1);
    });
});
