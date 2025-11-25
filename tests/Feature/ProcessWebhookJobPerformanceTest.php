<?php

/**
 * ProcessWebhookJobPerformanceTest - Story 1.2 Performance Tests
 *
 * Tests for performance requirements:
 * - AC-1.2.6: Processing time 99th percentile < 2 seconds
 *
 * @see docs/sprint-artifacts/1-2-create-processwebhookjob-for-background-processing.md
 */

use App\Jobs\Webhook\DownloadWebhookMediaJob;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Jobs\Webhook\ProcessWebhookNotificationsJob;
use App\Models\Chat;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([
        DownloadWebhookMediaJob::class,
        ProcessWebhookNotificationsJob::class,
    ]);
});

describe('AC-1.2.6: Performance Requirements', function () {
    it('processes single message under 2 seconds', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = [
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '0987654321', 'phone_number_id' => 'phone_id'],
                'contacts' => [['profile' => ['name' => 'Test User'], 'wa_id' => '1234567890']],
                'messages' => [
                    [
                        'from' => '1234567890',
                        'id' => 'wamid_perf_'.uniqid(),
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Performance test message'],
                    ],
                ],
            ],
        ];

        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

        $startTime = microtime(true);
        $job->handle();
        $processingTime = (microtime(true) - $startTime) * 1000;

        expect($processingTime)->toBeLessThan(2000, "Processing time {$processingTime}ms exceeds 2 second limit");
    });

    it('processes 10 sequential messages with consistent performance', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $times = [];

        for ($i = 0; $i < 10; $i++) {
            $payload = [
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '0987654321', 'phone_number_id' => 'phone_id'],
                    'contacts' => [['profile' => ['name' => 'Test User'], 'wa_id' => '1234567890']],
                    'messages' => [
                        [
                            'from' => '1234567890',
                            'id' => 'wamid_seq_'.$i.'_'.uniqid(),
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => "Sequential message {$i}"],
                        ],
                    ],
                ],
            ];

            $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

            $startTime = microtime(true);
            $job->handle();
            $times[] = (microtime(true) - $startTime) * 1000;
        }

        // Calculate statistics
        sort($times);
        $p99 = $times[(int) (count($times) * 0.99)] ?? end($times);
        $average = array_sum($times) / count($times);
        $max = max($times);

        expect($p99)->toBeLessThan(2000, "99th percentile {$p99}ms exceeds 2 second limit");
        expect($average)->toBeLessThan(1000, "Average processing time {$average}ms exceeds 1 second");

        // Verify all chats were created
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(10);
    });

    it('uses idempotency index efficiently for duplicate check', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $wamId = 'wamid_index_test_'.uniqid();

        // First: Create a chat
        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => 1,
            'wam_id' => $wamId,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'received',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        // Track queries to verify index usage
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'wam_id') && str_contains($query->sql, 'exists')) {
                $queryCount++;
            }
        });

        $payload = [
            'field' => 'messages',
            'value' => [
                'messages' => [
                    [
                        'from' => '1234567890',
                        'id' => $wamId, // Duplicate wam_id
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => 'Duplicate'],
                    ],
                ],
                'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
            ],
        ];

        $startTime = microtime(true);
        $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
        $job->handle();
        $duplicateCheckTime = (microtime(true) - $startTime) * 1000;

        // Duplicate check should be fast (index lookup)
        expect($duplicateCheckTime)->toBeLessThan(500, "Duplicate check took {$duplicateCheckTime}ms - index may not be used");

        // No new chat should be created
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(1);
    });

    it('handles different message types with similar performance', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $messageTypes = ['text', 'image', 'video', 'document', 'audio', 'location', 'contacts', 'interactive'];
        $times = [];

        foreach ($messageTypes as $type) {
            $message = [
                'from' => '1234567890',
                'id' => "wamid_type_{$type}_".uniqid(),
                'timestamp' => (string) time(),
                'type' => $type,
            ];

            // Add type-specific data
            switch ($type) {
                case 'text':
                    $message['text'] = ['body' => 'Test'];
                    break;
                case 'image':
                    $message['image'] = ['id' => 'media_123', 'mime_type' => 'image/jpeg'];
                    break;
                case 'video':
                    $message['video'] = ['id' => 'media_456', 'mime_type' => 'video/mp4'];
                    break;
                case 'document':
                    $message['document'] = ['id' => 'media_789', 'mime_type' => 'application/pdf'];
                    break;
                case 'audio':
                    $message['audio'] = ['id' => 'media_audio', 'mime_type' => 'audio/ogg'];
                    break;
                case 'location':
                    $message['location'] = ['latitude' => 37.7749, 'longitude' => -122.4194];
                    break;
                case 'contacts':
                    $message['contacts'] = [['name' => ['formatted_name' => 'John']]];
                    break;
                case 'interactive':
                    $message['interactive'] = ['type' => 'button_reply', 'button_reply' => ['id' => '1', 'title' => 'Yes']];
                    break;
            }

            $payload = [
                'field' => 'messages',
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
                    'messages' => [$message],
                ],
            ];

            $job = new ProcessWebhookJob($payload, $organization->id, 'messages');

            $startTime = microtime(true);
            $job->handle();
            $times[$type] = (microtime(true) - $startTime) * 1000;
        }

        // All message types should process under 2 seconds
        foreach ($times as $type => $time) {
            expect($time)->toBeLessThan(2000, "Message type '{$type}' took {$time}ms to process");
        }

        // Verify all chats were created
        $chatCount = Chat::where('organization_id', $organization->id)->count();
        expect($chatCount)->toBe(count($messageTypes));
    });
});

describe('Memory Usage', function () {
    it('maintains bounded memory for multiple messages', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $initialMemory = memory_get_usage(true);

        for ($i = 0; $i < 5; $i++) {
            $payload = [
                'field' => 'messages',
                'value' => [
                    'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
                    'messages' => [
                        [
                            'from' => '1234567890',
                            'id' => 'wamid_mem_'.$i.'_'.uniqid(),
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => "Memory test message {$i}"],
                        ],
                    ],
                ],
            ];

            $job = new ProcessWebhookJob($payload, $organization->id, 'messages');
            $job->handle();
        }

        $finalMemory = memory_get_usage(true);
        $memoryIncrease = ($finalMemory - $initialMemory) / 1024 / 1024; // MB

        // Memory increase should be reasonable (< 50MB for 5 messages)
        expect($memoryIncrease)->toBeLessThan(50, "Memory increased by {$memoryIncrease}MB - possible memory leak");
    });
});
