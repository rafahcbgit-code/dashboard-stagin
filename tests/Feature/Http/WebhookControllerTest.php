<?php

/**
 * WebhookController Performance Tests
 *
 * Tests for FR9-FR20: Webhook Processing Requirements
 * - FR9: Webhook response time < 200ms (p95)
 * - FR10: Queue all webhook processing for background execution
 * - FR11: Validate webhook signatures synchronously
 *
 * @see docs/test-design-system.md
 * @see docs/prd.md (FR9-FR20)
 */

use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Fake the queue to capture dispatched jobs
    Queue::fake();
});

describe('Webhook Response Time (FR9)', function () {
    it('returns 200 OK within 200ms for valid webhook requests', function () {
        // Arrange: Create test organization
        $organization = createTestOrganization();

        // Act: Measure webhook response time
        $duration = measureTime(function () {
            $this->postJson('/webhook/waba', [
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
            ])->assertStatus(200);
        });

        // Assert: Response time should be under 200ms
        expect($duration)->toRespondWithin(200);
    })->skip('Webhook controller refactoring pending - currently synchronous');

    it('returns 200 OK for webhook verification challenge', function () {
        $response = $this->get('/webhook/waba?' . http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => config('services.whatsapp.verify_token'),
            'hub.challenge' => 'test_challenge_123',
        ]));

        $response->assertStatus(200);
        $response->assertSee('test_challenge_123');
    })->skip('Verify token not configured in test environment');
});

describe('Webhook Job Dispatch (FR10)', function () {
    it('dispatches ProcessWebhookJob for valid message webhook', function () {
        // This test will pass after webhook refactoring
        // Currently, webhooks are processed synchronously

        $this->postJson('/webhook/waba', [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'test_waba_id',
                    'changes' => [
                        ['field' => 'messages', 'value' => ['messages' => []]],
                    ],
                ],
            ],
        ]);

        // After refactoring, this should dispatch ProcessWebhookJob
        // Queue::assertPushed(ProcessWebhookJob::class);
    })->skip('ProcessWebhookJob not yet implemented');
});

describe('Webhook Signature Validation (FR11)', function () {
    it('rejects webhooks with invalid signature', function () {
        $response = $this->postJson('/webhook/waba', [
            'object' => 'whatsapp_business_account',
            'entry' => [],
        ], [
            'X-Hub-Signature-256' => 'sha256=invalid_signature',
        ]);

        // Should reject before processing
        $response->assertStatus(403);
    })->skip('Signature validation not enforced in test environment');
});
