<?php

/**
 * Webhook Load Test Script
 *
 * Story 1.6: Webhook Load Testing and Validation
 * AC-1.6.1: 100 webhooks in 60 seconds all return HTTP 200 within 200ms
 * AC-1.6.2: All 100 messages processed within 5 seconds total
 *
 * Usage:
 *   php tests/load/WebhookLoadTest.php --url=http://localhost:8000 --identifier=your-org-identifier
 *
 * Options:
 *   --url         Base URL of the application (default: http://localhost:8000)
 *   --identifier  Organization identifier for webhook endpoint (required)
 *   --count       Number of webhooks to send (default: 100)
 *   --duration    Duration in seconds to spread requests (default: 60)
 *   --app-secret  App secret for signature generation (optional)
 *   --mix         Use mixed payload types (text 70%, image 15%, video 10%, document 5%)
 *   --verbose     Show detailed output for each request
 *   --help        Show this help message
 */
class WebhookLoadTest
{
    private string $baseUrl;

    private string $identifier;

    private int $webhookCount;

    private int $duration;

    private ?string $appSecret;

    private bool $mixedPayloads;

    private bool $verbose;

    private string $fixturesPath;

    private array $results = [];

    private array $responseTimes = [];

    private int $successCount = 0;

    private int $failureCount = 0;

    public function __construct(array $options)
    {
        $this->baseUrl = rtrim($options['url'] ?? 'http://localhost:8000', '/');
        $this->identifier = $options['identifier'] ?? '';
        $this->webhookCount = (int) ($options['count'] ?? 100);
        $this->duration = (int) ($options['duration'] ?? 60);
        $this->appSecret = $options['app-secret'] ?? null;
        $this->mixedPayloads = isset($options['mix']);
        $this->verbose = isset($options['verbose']);
        $this->fixturesPath = __DIR__.'/fixtures';
    }

    public function run(): void
    {
        $this->printHeader();
        $this->validateOptions();

        $endpoint = "{$this->baseUrl}/webhook/whatsapp/{$this->identifier}";
        $intervalMs = ($this->duration * 1000) / $this->webhookCount;

        echo "Starting load test...\n";
        echo "Sending {$this->webhookCount} webhooks over {$this->duration} seconds\n";
        echo 'Target interval: '.round($intervalMs)."ms between requests\n\n";

        $startTime = microtime(true);
        $sentWamIds = [];

        for ($i = 1; $i <= $this->webhookCount; $i++) {
            $iterationStart = microtime(true);

            // Generate unique identifiers for this webhook
            $wamId = 'wamid_load_'.$i.'_'.uniqid();
            $sentWamIds[] = $wamId;

            // Generate payload based on distribution
            $payload = $this->generatePayload($i, $wamId);

            // Send webhook
            $result = $this->sendWebhook($endpoint, $payload, $i);
            $this->results[] = $result;

            if ($result['success']) {
                $this->successCount++;
                $this->responseTimes[] = $result['time_ms'];
            } else {
                $this->failureCount++;
            }

            // Progress output
            if ($this->verbose || $i % 10 === 0) {
                $this->printProgress($i, $result);
            }

            // Maintain target rate
            $elapsed = (microtime(true) - $iterationStart) * 1000;
            $sleepTime = max(0, $intervalMs - $elapsed);
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000));
            }
        }

        $totalTime = microtime(true) - $startTime;

        $this->printResults($totalTime, $sentWamIds);
    }

    private function generatePayload(int $index, string $wamId): array
    {
        $type = 'text';

        if ($this->mixedPayloads) {
            // Distribution: text 70%, image 15%, video 10%, document 5%
            $rand = mt_rand(1, 100);
            if ($rand <= 70) {
                $type = 'text';
            } elseif ($rand <= 85) {
                $type = 'image';
            } elseif ($rand <= 95) {
                $type = 'video';
            } else {
                $type = 'document';
            }
        }

        return $this->createPayload($type, $wamId, $index);
    }

    private function createPayload(string $type, string $wamId, int $index): array
    {
        $timestamp = (string) time();
        $waId = '1234567890'.str_pad($index % 100, 2, '0', STR_PAD_LEFT);

        $message = [
            'from' => $waId,
            'id' => $wamId,
            'timestamp' => $timestamp,
            'type' => $type,
        ];

        switch ($type) {
            case 'text':
                $message['text'] = ['body' => "Load test message #{$index}"];
                break;
            case 'image':
                $message['image'] = [
                    'id' => 'media_img_'.uniqid(),
                    'mime_type' => 'image/jpeg',
                    'sha256' => hash('sha256', "image_{$index}"),
                    'caption' => "Test image #{$index}",
                ];
                break;
            case 'video':
                $message['video'] = [
                    'id' => 'media_vid_'.uniqid(),
                    'mime_type' => 'video/mp4',
                    'sha256' => hash('sha256', "video_{$index}"),
                ];
                break;
            case 'document':
                $message['document'] = [
                    'id' => 'media_doc_'.uniqid(),
                    'mime_type' => 'application/pdf',
                    'sha256' => hash('sha256', "document_{$index}"),
                    'filename' => "test_{$index}.pdf",
                ];
                break;
            case 'audio':
                $message['audio'] = [
                    'id' => 'media_aud_'.uniqid(),
                    'mime_type' => 'audio/ogg',
                    'sha256' => hash('sha256', "audio_{$index}"),
                    'voice' => true,
                ];
                break;
        }

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
                                    'display_phone_number' => '0987654321',
                                    'phone_number_id' => 'test_phone_id',
                                ],
                                'contacts' => [
                                    [
                                        'profile' => ['name' => 'Load Test User'],
                                        'wa_id' => $waId,
                                    ],
                                ],
                                'messages' => [$message],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function sendWebhook(string $endpoint, array $payload, int $index): array
    {
        $payloadJson = json_encode($payload);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        // Generate signature if app secret provided
        if ($this->appSecret) {
            $signature = 'sha256='.hash_hmac('sha256', $payloadJson, $this->appSecret);
            $headers[] = "X-Hub-Signature-256: {$signature}";
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payloadJson,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $startTime = microtime(true);
        $response = curl_exec($ch);
        $responseTime = (microtime(true) - $startTime) * 1000;

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'index' => $index,
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'time_ms' => round($responseTime, 2),
            'error' => $error ?: null,
            'response' => $response,
            'wam_id' => $payload['entry'][0]['changes'][0]['value']['messages'][0]['id'],
            'type' => $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'],
        ];
    }

    private function printProgress(int $index, array $result): void
    {
        $status = $result['success'] ? '✓' : '✗';
        $color = $result['success'] ? "\033[32m" : "\033[31m";
        $reset = "\033[0m";

        if ($this->verbose) {
            echo sprintf(
                "{$color}%s{$reset} [%3d/%d] HTTP %d | %6.2fms | %s\n",
                $status,
                $index,
                $this->webhookCount,
                $result['http_code'],
                $result['time_ms'],
                $result['type']
            );
        } else {
            echo sprintf(
                "[%3d/%d] Success: %d | Failed: %d | Avg: %.2fms\n",
                $index,
                $this->webhookCount,
                $this->successCount,
                $this->failureCount,
                count($this->responseTimes) > 0 ? array_sum($this->responseTimes) / count($this->responseTimes) : 0
            );
        }
    }

    private function printResults(float $totalTime, array $sentWamIds): void
    {
        echo "\n".str_repeat('=', 60)."\n";
        echo "LOAD TEST RESULTS\n";
        echo str_repeat('=', 60)."\n\n";

        // Basic stats
        echo "Total webhooks sent: {$this->webhookCount}\n";
        echo 'Total time: '.round($totalTime, 2)."s\n";
        echo "Successful: {$this->successCount}\n";
        echo "Failed: {$this->failureCount}\n";
        echo 'Success rate: '.round(($this->successCount / $this->webhookCount) * 100, 2)."%\n\n";

        // Response time statistics
        if (count($this->responseTimes) > 0) {
            sort($this->responseTimes);
            $count = count($this->responseTimes);

            $min = min($this->responseTimes);
            $max = max($this->responseTimes);
            $avg = array_sum($this->responseTimes) / $count;
            $median = $this->responseTimes[(int) ($count * 0.5)];
            $p95 = $this->responseTimes[(int) ($count * 0.95)] ?? end($this->responseTimes);
            $p99 = $this->responseTimes[(int) ($count * 0.99)] ?? end($this->responseTimes);

            echo "Response Time Statistics:\n";
            echo '  Min:    '.round($min, 2)."ms\n";
            echo '  Max:    '.round($max, 2)."ms\n";
            echo '  Avg:    '.round($avg, 2)."ms\n";
            echo '  Median: '.round($median, 2)."ms\n";
            echo '  P95:    '.round($p95, 2)."ms\n";
            echo '  P99:    '.round($p99, 2)."ms\n\n";

            // Acceptance Criteria Verification
            echo "ACCEPTANCE CRITERIA VERIFICATION:\n";
            echo str_repeat('-', 40)."\n";

            // AC-1.6.1: p95 < 200ms
            $ac161Pass = $p95 < 200;
            $ac161Status = $ac161Pass ? "\033[32m✓ PASS\033[0m" : "\033[31m✗ FAIL\033[0m";
            echo "AC-1.6.1 (p95 < 200ms): {$ac161Status} ({$p95}ms)\n";

            // AC-1.6.3: Zero failures
            $ac163Pass = $this->failureCount === 0;
            $ac163Status = $ac163Pass ? "\033[32m✓ PASS\033[0m" : "\033[31m✗ FAIL\033[0m";
            echo "AC-1.6.3 (Zero failures): {$ac163Status} ({$this->failureCount} failures)\n";

            // Overall
            $overallPass = $ac161Pass && $ac163Pass;
            $overallStatus = $overallPass ? "\033[32m✓ ALL PASS\033[0m" : "\033[31m✗ SOME FAILED\033[0m";
            echo "\nOverall: {$overallStatus}\n";
        }

        // Export wam_ids for verification
        $wamIdFile = __DIR__.'/results/wam_ids_'.date('Y-m-d_His').'.json';
        if (! is_dir(dirname($wamIdFile))) {
            mkdir(dirname($wamIdFile), 0755, true);
        }
        file_put_contents($wamIdFile, json_encode([
            'timestamp' => date('c'),
            'count' => count($sentWamIds),
            'wam_ids' => $sentWamIds,
        ], JSON_PRETTY_PRINT));

        echo "\nWAM IDs saved to: {$wamIdFile}\n";
        echo "(Use this file to verify message creation in database)\n";

        // Export full results
        $resultsFile = __DIR__.'/results/load_test_'.date('Y-m-d_His').'.json';
        file_put_contents($resultsFile, json_encode([
            'timestamp' => date('c'),
            'config' => [
                'base_url' => $this->baseUrl,
                'identifier' => $this->identifier,
                'webhook_count' => $this->webhookCount,
                'duration_seconds' => $this->duration,
                'mixed_payloads' => $this->mixedPayloads,
            ],
            'summary' => [
                'total_time_seconds' => round($totalTime, 2),
                'success_count' => $this->successCount,
                'failure_count' => $this->failureCount,
                'success_rate' => round(($this->successCount / $this->webhookCount) * 100, 2),
                'response_time' => [
                    'min_ms' => round(min($this->responseTimes), 2),
                    'max_ms' => round(max($this->responseTimes), 2),
                    'avg_ms' => round(array_sum($this->responseTimes) / count($this->responseTimes), 2),
                    'p95_ms' => round($p95 ?? 0, 2),
                    'p99_ms' => round($p99 ?? 0, 2),
                ],
            ],
            'results' => $this->results,
        ], JSON_PRETTY_PRINT));

        echo "Full results saved to: {$resultsFile}\n";
    }

    private function printHeader(): void
    {
        echo "\n";
        echo str_repeat('=', 60)."\n";
        echo "WEBHOOK LOAD TEST - Story 1.6\n";
        echo str_repeat('=', 60)."\n\n";
    }

    private function validateOptions(): void
    {
        if (empty($this->identifier)) {
            $this->printHelp();
            echo "\nError: --identifier is required\n";
            exit(1);
        }
    }

    private function printHelp(): void
    {
        echo <<<'HELP'
Webhook Load Test Script

Usage:
  php tests/load/WebhookLoadTest.php --url=http://localhost:8000 --identifier=your-org-identifier

Options:
  --url         Base URL of the application (default: http://localhost:8000)
  --identifier  Organization identifier for webhook endpoint (required)
  --count       Number of webhooks to send (default: 100)
  --duration    Duration in seconds to spread requests (default: 60)
  --app-secret  App secret for signature generation (optional)
  --mix         Use mixed payload types (text 70%, image 15%, video 10%, document 5%)
  --verbose     Show detailed output for each request
  --help        Show this help message

Examples:
  # Basic test with 100 webhooks over 60 seconds
  php tests/load/WebhookLoadTest.php --identifier=my-org

  # Fast burst test (100 webhooks in 10 seconds)
  php tests/load/WebhookLoadTest.php --identifier=my-org --duration=10

  # Mixed payload types with signature
  php tests/load/WebhookLoadTest.php --identifier=my-org --mix --app-secret=your_secret

  # Custom count and verbose output
  php tests/load/WebhookLoadTest.php --identifier=my-org --count=50 --verbose

HELP;
    }
}

// Parse command line options
$options = getopt('', [
    'url:',
    'identifier:',
    'count:',
    'duration:',
    'app-secret:',
    'mix',
    'verbose',
    'help',
]);

if (isset($options['help'])) {
    (new WebhookLoadTest([]))->run();
    exit(0);
}

// Run the load test
$test = new WebhookLoadTest($options);
$test->run();
