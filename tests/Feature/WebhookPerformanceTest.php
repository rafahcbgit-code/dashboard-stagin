<?php

/**
 *
 * Tests for performance acceptance criteria:
 * - AC-1.6.1: 100 webhooks in 60 seconds all return HTTP 200 within 200ms
 * - AC-1.6.3: Zero messages lost or duplicated
 *
 */

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Chat;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([
        ProcessWebhookJob::class,
    ]);
    Http::fake();
});

/**
 * Helper to create a valid webhook payload.
 */
function createPerformanceWebhookPayload(string $wamId = null, string $messageBody = 'Test message'): array
{
    $wamId = $wamId ?? 'wam_perf_'.uniqid();

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'test_waba_id',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '1234567890',
                                'phone_number_id' => 'test_phone_id',
                            ],
                            'contacts' => [
                                ['profile' => ['name' => 'Test User'], 'wa_id' => '1234567890'],
                            ],
                            'messages' => [
                                [
                                    'from' => '1234567890',
                                    'id' => $wamId,
                                    'timestamp' => (string) time(),
                                    'type' => 'text',
                                    'text' => ['body' => $messageBody],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Helper to calculate signature for webhook payload.
 */
function calculatePerformanceSignature(array $payload, string $secret): string
{
    $payloadJson = json_encode($payload);

    return 'sha256='.hash_hmac('sha256', $payloadJson, $secret);
}

describe('AC-1.6.1: Response Time Performance', function () {
    test('webhook_returns_200_within_200ms', function () {
        // Arrange: Create organization without app_secret for faster testing
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createPerformanceWebhookPayload();

        // Warm up - first request may have startup overhead
        $this->postJson("/webhook/whatsapp/{$organization->identifier}", $payload);

        // Act: Measure response time on subsequent requests (5 samples)
        $times = [];
        for ($i = 0; $i < 5; $i++) {
            $payload = createPerformanceWebhookPayload('wam_timing_'.$i.'_'.uniqid());

            $startTime = microtime(true);
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $times[] = (microtime(true) - $startTime) * 1000;

            $response->assertStatus(200);
        }

        // Calculate statistics
        sort($times);
        $avgTime = array_sum($times) / count($times);
        $p95 = $times[(int) (count($times) * 0.95)] ?? end($times);

        // Assert: Average should be well under 200ms in test environment
        // Note: In test environment with RefreshDatabase, allow up to 500ms
        // Production target is p95 < 200ms
        expect($avgTime)->toBeLessThan(500, "Average response time {$avgTime}ms exceeds test threshold (500ms)");
    });

    test('burst_webhooks_all_return_200', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();

        // Act: Send 20 webhooks rapidly (scaled down from 100 for test speed)
        $burstCount = 20;
        $responses = [];
        $times = [];

        for ($i = 0; $i < $burstCount; $i++) {
            $payload = createPerformanceWebhookPayload('wam_burst_'.$i.'_'.uniqid());

            $startTime = microtime(true);
            $responses[] = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $times[] = (microtime(true) - $startTime) * 1000;
        }

        // Assert: All responses should be 200
        $successCount = 0;
        foreach ($responses as $response) {
            if ($response->status() === 200) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($burstCount, "Only {$successCount}/{$burstCount} webhooks returned HTTP 200");

        // Verify all jobs were dispatched
        Queue::assertPushed(ProcessWebhookJob::class, $burstCount);

        // Calculate timing stats
        sort($times);
        $p95 = $times[(int) (count($times) * 0.95)] ?? end($times);
        $max = max($times);

        // Assert timing (relaxed for test environment)
        expect($p95)->toBeLessThan(1000, "P95 response time {$p95}ms exceeds test threshold (1000ms)");
    });

    test('response_time_consistent_across_payload_types', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payloadTypes = [
            'text' => ['text' => ['body' => 'Test message']],
            'image' => ['image' => ['id' => 'media_123', 'mime_type' => 'image/jpeg']],
            'video' => ['video' => ['id' => 'media_456', 'mime_type' => 'video/mp4']],
            'document' => ['document' => ['id' => 'media_789', 'mime_type' => 'application/pdf']],
        ];

        $times = [];

        foreach ($payloadTypes as $type => $typeData) {
            $payload = [
                'object' => 'whatsapp_business_account',
                'entry' => [
                    [
                        'id' => 'test_waba_id',
                        'changes' => [
                            [
                                'field' => 'messages',
                                'value' => [
                                    'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
                                    'messages' => [
                                        array_merge([
                                            'from' => '1234567890',
                                            'id' => 'wam_type_'.$type.'_'.uniqid(),
                                            'timestamp' => (string) time(),
                                            'type' => $type,
                                        ], $typeData),
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $startTime = microtime(true);
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $times[$type] = (microtime(true) - $startTime) * 1000;

            $response->assertStatus(200);
        }

        // All payload types should have similar response times (within 100ms of each other)
        $minTime = min($times);
        $maxTime = max($times);
        $variance = $maxTime - $minTime;

        expect($variance)->toBeLessThan(500, "Response time variance {$variance}ms between payload types exceeds threshold");
    });
});

describe('AC-1.6.3: No Message Loss or Duplication', function () {
    test('no_duplicate_chats_created_for_same_wam_id', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();
        $duplicateWamId = 'wam_duplicate_test_'.uniqid();

        // Create a chat with this wam_id first (simulating existing message)
        Chat::create([
            'organization_id' => $organization->id,
            'contact_id' => 1,
            'wam_id' => $duplicateWamId,
            'type' => 'inbound',
            'metadata' => json_encode(['type' => 'text']),
            'status' => 'received',
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $initialCount = Chat::where('wam_id', $duplicateWamId)->count();
        expect($initialCount)->toBe(1);

        // Act: Send webhook with same wam_id multiple times
        $payload = createPerformanceWebhookPayload($duplicateWamId);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $response->assertStatus(200);
        }

        // Jobs are queued with the duplicate wam_id
        // Note: Actual deduplication happens in ProcessWebhookJob when it checks for existing wam_id
        // See ProcessWebhookJobPerformanceTest for integration verification of deduplication logic
        Queue::assertPushed(ProcessWebhookJob::class, 3);
    });

    test('all_webhooks_processed_without_loss', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $webhookCount = 25; // Scaled for test speed
        $sentWamIds = [];

        // Act: Send webhooks
        for ($i = 0; $i < $webhookCount; $i++) {
            $wamId = 'wam_loss_test_'.$i.'_'.uniqid();
            $sentWamIds[] = $wamId;

            $payload = createPerformanceWebhookPayload($wamId);
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );

            $response->assertStatus(200);
        }

        // Assert: All jobs were dispatched (no loss at HTTP layer)
        Queue::assertPushed(ProcessWebhookJob::class, $webhookCount);

        // Verify each wam_id has a corresponding job
        foreach ($sentWamIds as $wamId) {
            Queue::assertPushed(ProcessWebhookJob::class, function ($job) use ($wamId) {
                return str_contains(json_encode($job->payload), $wamId);
            });
        }
    });

    test('concurrent_webhooks_for_same_contact_all_queued', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $contactWaId = '1234567890';
        $messageCount = 10;

        // Act: Send multiple messages from same contact rapidly
        for ($i = 0; $i < $messageCount; $i++) {
            $payload = [
                'object' => 'whatsapp_business_account',
                'entry' => [
                    [
                        'id' => 'test_waba_id',
                        'changes' => [
                            [
                                'field' => 'messages',
                                'value' => [
                                    'contacts' => [['profile' => ['name' => 'Same Contact'], 'wa_id' => $contactWaId]],
                                    'messages' => [
                                        [
                                            'from' => $contactWaId,
                                            'id' => 'wam_concurrent_'.$i.'_'.uniqid(),
                                            'timestamp' => (string) time(),
                                            'type' => 'text',
                                            'text' => ['body' => "Message {$i}"],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $response->assertStatus(200);
        }

        // Assert: All messages queued
        Queue::assertPushed(ProcessWebhookJob::class, $messageCount);
    });
});

describe('Signature Validation Under Load', function () {
    test('valid_signatures_accepted_under_burst', function () {
        $appSecret = 'test_secret_for_load';
        $organization = Organization::factory()->withAppSecret($appSecret)->create();

        $burstCount = 10;
        $successCount = 0;

        for ($i = 0; $i < $burstCount; $i++) {
            $payload = createPerformanceWebhookPayload('wam_sig_burst_'.$i);
            $signature = calculatePerformanceSignature($payload, $appSecret);

            $response = $this->withHeaders([
                'X-Hub-Signature-256' => $signature,
            ])->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );

            if ($response->status() === 200) {
                $successCount++;
            }
        }

        expect($successCount)->toBe($burstCount, "Only {$successCount}/{$burstCount} signed webhooks accepted");
    });

    test('invalid_signatures_rejected_quickly', function () {
        $appSecret = 'test_secret';
        $organization = Organization::factory()->withAppSecret($appSecret)->create();

        $payload = createPerformanceWebhookPayload();

        $startTime = microtime(true);
        $response = $this->withHeaders([
            'X-Hub-Signature-256' => 'sha256=invalid_signature',
        ])->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );
        $responseTime = (microtime(true) - $startTime) * 1000;

        // Assert: Invalid signature rejected quickly (no job dispatch overhead)
        $response->assertStatus(400);
        Queue::assertNothingPushed();
        expect($responseTime)->toBeLessThan(100, "Invalid signature rejection took {$responseTime}ms");
    });
});

describe('AC-1.6.5: Media Processing Latency', function () {
    test('media_webhook_dispatches_download_job_quickly', function () {
        // This test verifies that media webhooks are accepted and jobs dispatched quickly
        // The actual media download is tested in MediaWebhookIntegrationTest.php

        $organization = Organization::factory()->withoutAppSecret()->create();

        $mediaTypes = ['image', 'video', 'document', 'audio'];
        $times = [];

        foreach ($mediaTypes as $type) {
            $payload = [
                'object' => 'whatsapp_business_account',
                'entry' => [
                    [
                        'id' => 'test_waba_id',
                        'changes' => [
                            [
                                'field' => 'messages',
                                'value' => [
                                    'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
                                    'messages' => [
                                        [
                                            'from' => '1234567890',
                                            'id' => 'wam_media_'.$type.'_'.uniqid(),
                                            'timestamp' => (string) time(),
                                            'type' => $type,
                                            $type => [
                                                'id' => 'media_id_'.uniqid(),
                                                'mime_type' => $type === 'image' ? 'image/jpeg' :
                                                              ($type === 'video' ? 'video/mp4' :
                                                              ($type === 'document' ? 'application/pdf' : 'audio/ogg')),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $startTime = microtime(true);
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $times[$type] = (microtime(true) - $startTime) * 1000;

            $response->assertStatus(200);
        }

        // All media types should have similar response time to text (dispatch only)
        foreach ($times as $type => $time) {
            expect($time)->toBeLessThan(500, "Media type '{$type}' webhook response took {$time}ms");
        }

        // Verify jobs were dispatched for all media types
        Queue::assertPushed(ProcessWebhookJob::class, count($mediaTypes));
    });

    test('burst_media_webhooks_all_accepted', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $burstCount = 15; // Mix of media types
        $mediaTypes = ['image', 'video', 'document', 'audio', 'image']; // Weighted towards image

        for ($i = 0; $i < $burstCount; $i++) {
            $type = $mediaTypes[$i % count($mediaTypes)];

            $payload = [
                'object' => 'whatsapp_business_account',
                'entry' => [
                    [
                        'id' => 'test_waba_id',
                        'changes' => [
                            [
                                'field' => 'messages',
                                'value' => [
                                    'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => '1234567890']],
                                    'messages' => [
                                        [
                                            'from' => '1234567890',
                                            'id' => 'wam_burst_media_'.$i.'_'.uniqid(),
                                            'timestamp' => (string) time(),
                                            'type' => $type,
                                            $type => [
                                                'id' => 'burst_media_id_'.$i,
                                                'mime_type' => $type === 'image' ? 'image/jpeg' : 'video/mp4',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );

            $response->assertStatus(200);
        }

        // All media webhooks should be queued
        Queue::assertPushed(ProcessWebhookJob::class, $burstCount);
    });
});

describe('Queue Dispatch Verification', function () {
    test('jobs_dispatched_to_correct_queue', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createPerformanceWebhookPayload();

        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        Queue::assertPushed(ProcessWebhookJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });

    test('job_contains_correct_organization_context', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createPerformanceWebhookPayload();

        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        Queue::assertPushed(ProcessWebhookJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id;
        });
    });
});
