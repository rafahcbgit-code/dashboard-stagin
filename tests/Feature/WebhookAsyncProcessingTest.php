<?php

/**
 * WebhookAsyncProcessingTest - Story 1.1 Tests
 *
 * Tests for the Immediate 200 OK Pattern implementation (ADR-001).
 *
 * Acceptance Criteria:
 * - AC-1.1.1: WebhookController returns HTTP 200 within 200ms for valid webhooks
 * - AC-1.1.2: Signature validation completes BEFORE any job dispatch
 * - AC-1.1.3: ProcessWebhookJob is dispatched to webhook-high queue
 * - AC-1.1.4: No database writes occur in HTTP request thread
 * - AC-1.1.5: No external API calls occur in HTTP request thread
 * - AC-1.1.6: Webhook receipt timestamp is logged for monitoring
 *
 * @see docs/sprint-artifacts/1-1-refactor-webhookcontroller-for-immediate-response.md
 */

use App\Jobs\Webhook\ProcessWebhookJob;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(); // Prevent any external HTTP calls
});

/**
 * Helper to create a valid webhook payload.
 */
function createWebhookPayload(string $field = 'messages', array $value = []): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'test_waba_id',
                'changes' => [
                    [
                        'field' => $field,
                        'value' => $value ?: [
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
                                    'id' => 'wam_test_' . time(),
                                    'timestamp' => (string) time(),
                                    'type' => 'text',
                                    'text' => ['body' => 'Hello, World!'],
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
function calculateSignature(array $payload, string $secret): string
{
    $payloadJson = json_encode($payload);
    return 'sha256=' . hash_hmac('sha256', $payloadJson, $secret);
}

describe('AC-1.1.1: Webhook Response Time', function () {
    it('returns HTTP 200 within acceptable time for valid webhooks', function () {
        // Arrange: Create organization without app_secret to skip signature validation
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createWebhookPayload();

        // Warm up - first request has overhead
        $this->postJson("/webhook/whatsapp/{$organization->identifier}", $payload);

        // Act: Measure response time on subsequent requests
        $times = [];
        for ($i = 0; $i < 3; $i++) {
            $startTime = microtime(true);
            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );
            $times[] = (microtime(true) - $startTime) * 1000;
        }

        $avgTime = array_sum($times) / count($times);

        // Assert
        $response->assertStatus(200);
        $response->assertJson(['status' => 'success']);

        // In test environment, allow up to 500ms due to DB refresh overhead
        // In production, this should be under 200ms (p95)
        // The key validation is that we're dispatching a job, not processing synchronously
        expect($avgTime)->toBeLessThan(500, "Average response time {$avgTime}ms exceeds test threshold");
    });

    it('returns HTTP 200 for all webhook types', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $webhookTypes = [
            'messages',
            'smb_message_echoes',
            'smb_app_state_sync',
            'history',
            'message_template_status_update',
            'account_review_update',
            'phone_number_name_update',
            'phone_number_quality_update',
            'business_capability_update',
        ];

        foreach ($webhookTypes as $type) {
            $payload = createWebhookPayload($type, ['test' => 'data']);

            $response = $this->postJson(
                "/webhook/whatsapp/{$organization->identifier}",
                $payload
            );

            $response->assertStatus(200);
        }
    });
});

describe('AC-1.1.2: Signature Validation Before Job Dispatch', function () {
    it('rejects webhook with invalid signature before dispatching job', function () {
        // Arrange: Create organization WITH app_secret for signature validation
        $appSecret = 'test_secret_for_validation';
        $organization = Organization::factory()->withAppSecret($appSecret)->create();

        $payload = createWebhookPayload();

        // Act: Send with invalid signature
        $response = $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload,
            ['X-Hub-Signature-256' => 'sha256=invalid_signature']
        );

        // Assert: Should reject before dispatching
        $response->assertStatus(400);

        // No job should be dispatched
        Queue::assertNothingPushed();
    });

    it('accepts webhook with valid signature', function () {
        // Arrange
        $appSecret = 'test_secret_for_validation';
        $organization = Organization::factory()->withAppSecret($appSecret)->create();

        $payload = createWebhookPayload();
        $signature = calculateSignature($payload, $appSecret);

        // Act: Send with valid signature
        $response = $this->withHeaders([
            'X-Hub-Signature-256' => $signature,
        ])->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert
        $response->assertStatus(200);
        Queue::assertPushed(ProcessWebhookJob::class);
    });

    it('bypasses signature validation when app_secret is not configured', function () {
        // Arrange: Organization without app_secret
        $organization = Organization::factory()->withoutAppSecret()->create();

        $payload = createWebhookPayload();

        // Act: Send without signature header
        $response = $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert: Should accept and process
        $response->assertStatus(200);
        Queue::assertPushed(ProcessWebhookJob::class);
    });
});

describe('AC-1.1.3: ProcessWebhookJob Dispatched to Correct Queue', function () {
    it('dispatches ProcessWebhookJob to webhook-high queue', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createWebhookPayload('messages');

        // Act
        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert
        Queue::assertPushed(ProcessWebhookJob::class, function ($job) {
            return $job->queue === 'webhook-high';
        });
    });

    it('includes correct payload data in dispatched job', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createWebhookPayload('messages', [
            'messages' => [['id' => 'test_msg_id', 'text' => ['body' => 'Test']]],
        ]);

        // Act
        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert
        Queue::assertPushed(ProcessWebhookJob::class, function ($job) use ($organization) {
            return $job->organizationId === $organization->id
                && $job->webhookType === 'messages'
                && isset($job->payload['field'])
                && $job->payload['field'] === 'messages';
        });
    });
});

describe('AC-1.1.4: No Database Writes in Request Thread', function () {
    it('does not write to database during webhook request', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createWebhookPayload();

        // Capture query count before
        $queryCount = 0;
        \DB::listen(function ($query) use (&$queryCount) {
            // Count only INSERT, UPDATE, DELETE queries
            if (preg_match('/^(insert|update|delete)/i', trim($query->sql))) {
                $queryCount++;
            }
        });

        // Act
        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert: No write queries should have been executed
        // (Only reads for organization lookup and queue job insertion are expected)
        expect($queryCount)->toBe(0);
    });
});

describe('AC-1.1.5: No External API Calls in Request Thread', function () {
    it('does not make external API calls during webhook request', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createWebhookPayload('messages', [
            'messages' => [
                [
                    'id' => 'wam_test',
                    'from' => '1234567890',
                    'type' => 'image', // Media type that would normally trigger API call
                    'image' => ['id' => 'media_id_123'],
                ],
            ],
        ]);

        Http::fake(); // Ensure all HTTP calls are faked

        // Act
        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );

        // Assert: No HTTP requests should have been made
        Http::assertNothingSent();
    });
});

describe('AC-1.1.6: Webhook Logging', function () {
    it('logs webhook receipt with timestamp and organization', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $payload = createWebhookPayload('messages');

        Log::shouldReceive('channel')
            ->with('webhook')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->with('Webhook received and queued', \Mockery::on(function ($context) use ($organization) {
                return isset($context['organization_id'])
                    && $context['organization_id'] === $organization->id
                    && isset($context['webhook_type'])
                    && $context['webhook_type'] === 'messages'
                    && isset($context['response_time_ms'])
                    && isset($context['timestamp']);
            }));

        // Act
        $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $payload
        );
    });

    it('logs warning for invalid payload structure', function () {
        // Arrange
        $organization = Organization::factory()->withoutAppSecret()->create();
        $invalidPayload = ['invalid' => 'structure'];

        Log::shouldReceive('channel')
            ->with('webhook')
            ->andReturnSelf();

        Log::shouldReceive('warning')
            ->once()
            ->with('Invalid webhook payload structure', \Mockery::on(function ($context) use ($organization) {
                return isset($context['organization_id'])
                    && $context['organization_id'] === $organization->id;
            }));

        // Act
        $response = $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            $invalidPayload
        );

        // Assert
        $response->assertStatus(400);
    });
});

describe('Edge Cases and Error Handling', function () {
    it('returns 403 for non-existent organization', function () {
        $payload = createWebhookPayload();

        $response = $this->postJson(
            '/webhook/whatsapp/non-existent-identifier',
            $payload
        );

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    });

    it('returns 400 for empty entry array', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $response = $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            ['entry' => []]
        );

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    });

    it('returns 400 for missing changes in entry', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $response = $this->postJson(
            "/webhook/whatsapp/{$organization->identifier}",
            ['entry' => [['id' => 'test']]]
        );

        $response->assertStatus(400);
        Queue::assertNothingPushed();
    });

    it('handles webhook verification GET request', function () {
        $organization = Organization::factory()->withoutAppSecret()->create();

        $response = $this->get(
            "/webhook/whatsapp/{$organization->identifier}?" . http_build_query([
                'hub_mode' => 'subscribe',
                'hub_verify_token' => $organization->identifier,
                'hub_challenge' => 'test_challenge_123',
            ])
        );

        $response->assertStatus(200);
        $response->assertSee('test_challenge_123');
    });
});

describe('ProcessWebhookJob Properties', function () {
    it('job has correct retry configuration', function () {
        $job = new ProcessWebhookJob(
            ['field' => 'messages', 'value' => []],
            1,
            'messages'
        );

        expect($job->tries)->toBe(3);
        expect($job->timeout)->toBe(120);
        expect($job->backoff)->toBe([60, 300, 900]);
        // Queue is set via onQueue() in constructor, stored in trait property
        expect($job->queue)->toBe('webhook-high');
    });

    it('job stores payload and organization correctly', function () {
        $payload = ['field' => 'messages', 'value' => ['test' => 'data']];
        $organizationId = 123;
        $webhookType = 'messages';

        $job = new ProcessWebhookJob($payload, $organizationId, $webhookType);

        expect($job->payload)->toBe($payload);
        expect($job->organizationId)->toBe($organizationId);
        expect($job->webhookType)->toBe($webhookType);
    });
});
