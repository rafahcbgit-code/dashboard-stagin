<?php

namespace App\Jobs;

use App\Events\CampaignProgressEvent;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Template;
use App\Services\OrganizationService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait;

    public int $timeout = 600;
    public int $tries = 2;

    protected static array $campaignCache = [];
    protected static array $templateCache = [];
    protected static array $whatsappServiceCache = [];
    protected static array $progressState = [];

    public function __construct(
        public array $logIds,
        public int $organizationId,
        public int $campaignId,
        public int $templateId,
        public int $createdBy,
        public int $concurrency = 10,
        public int $requestDelayMs = 100,
    ) {
        $this->queue = 'campaign-messages';
    }

    public function handle(): void
    {
        $startTime = microtime(true);
        $isLoadTest = config('campaigns.load_test_mode', false);

        // 1. Bulk-lock all logs for this chunk
        $logs = CampaignLog::whereIn('id', $this->logIds)
            ->whereIn('status', ['pending', 'failed'])
            ->lockForUpdate()
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        // 2. Check campaign not cancelled (static-cached)
        $campaign = $this->getCampaignCached();
        if (!$campaign || $campaign->status === 'cancelled') {
            CampaignLog::whereIn('id', $logs->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
            return;
        }

        // 3. Bulk-load contacts
        $contactIds = $logs->pluck('contact_id')->unique()->toArray();
        $contacts = Contact::whereIn('id', $contactIds)->get()->keyBy('id');

        // 4. Load template (static-cached)
        $template = $this->getTemplateCached();
        $templateMetadata = json_decode($template->metadata, true);
        $isCarousel = ($templateMetadata['template_type'] ?? '') === 'carousel';

        // 5. Initialize WhatsappService once
        $whatsappService = $this->getWhatsappService();

        $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        // 6. Mark all logs as 'ongoing' in one bulk update
        CampaignLog::whereIn('id', $logs->pluck('id'))
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'ongoing']);

        // 7. Build template payloads
        $payloads = [];

        foreach ($logs as $log) {
            $contact = $contacts->get($log->contact_id);
            if (!$contact || ($contact->is_blocked ?? false)) {
                $log->update(['status' => 'failed']);
                continue;
            }

            try {
                if ($isCarousel) {
                    $templatePayload = $this->buildCarouselTemplateRequest(
                        $template->id,
                        $contact,
                        $template
                    );
                    $templatePayload = $whatsappService->enrichCarouselWithMediaIds(
                        $templatePayload,
                        $this->campaignId
                    );

                    if ($whatsappService->carouselPayloadHasMediaLinks($templatePayload)) {
                        $log->update([
                            'status' => 'failed',
                            'metadata' => json_encode([
                                'success' => false,
                                'message' => 'Unable to upload carousel media before sending.',
                                'code' => 'CAROUSEL_MEDIA_UPLOAD_FAILED',
                            ]),
                        ]);
                        continue;
                    }
                } else {
                    $templatePayload = $this->buildTemplateRequest(
                        $this->campaignId,
                        $contact,
                        $campaign,
                        $template
                    );

                    $validationError = $this->validateResolvedTemplate($templatePayload);
                    if ($validationError) {
                        $log->update([
                            'status' => 'failed',
                            'metadata' => json_encode([
                                'success' => false,
                                'message' => $validationError,
                            ]),
                        ]);
                        continue;
                    }
                }

                $flowStubs = $templatePayload['_flow_stubs'] ?? [];
                unset($templatePayload['_flow_stubs']);

                $templatePayload = $whatsappService->enrichTemplateHeaderWithMediaId(
                    $templatePayload,
                    $template,
                    [
                        'campaign_id' => $this->campaignId,
                        'campaign_log_id' => $log->id,
                        'template_id' => $template->id,
                        'contact_id' => $contact->id,
                    ]
                );

                // Build raw request payload
                $requestData = $whatsappService->buildTemplateRequestPayload(
                    $contact->phone,
                    $templatePayload
                );

                // Debug: log payload details for diagnosing template errors
                Log::channel('campaign')->info('CampaignChunkJob sending payload', [
                    'log_id' => $log->id,
                    'campaign_id' => $this->campaignId,
                    'organization_id' => $this->organizationId,
                    'template_name' => $templatePayload['name'] ?? null,
                    'template_language' => $templatePayload['language'] ?? null,
                    'contact_phone' => $contact->phone,
                    'send_url' => $whatsappService->getSendUrl(),
                    'phone_number_id' => $whatsappService->getPhoneNumberIdProperty(),
                    'payload_components_count' => count($templatePayload['components'] ?? []),
                    'has_flow_stubs' => !empty($flowStubs),
                ]);

                $payloads[] = [
                    'log' => $log,
                    'contact' => $contact,
                    'requestData' => $requestData,
                    'templateContent' => $templatePayload,
                    'flowStubs' => $flowStubs,
                ];
            } catch (\Exception $e) {
                Log::error('Error building template payload for campaign log', [
                    'log_id' => $log->id,
                    'error' => $e->getMessage(),
                ]);
                $log->update(['status' => 'failed']);
            }
        }

        if (empty($payloads)) {
            $this->broadcastProgressIfNeeded();
            $this->checkAndUpdateCampaignStatus();
            return;
        }

        // 8. Send concurrently via Guzzle Pool (or skip in load test mode)
        // Debug: For FLOW templates, also send via sendTemplateMessage to compare
        $hasFlowStubs = !empty(array_filter($payloads, fn($p) => !empty($p['flowStubs'])));
        if ($hasFlowStubs && !empty($payloads)) {
            $testPayload = $payloads[0];
            try {
                $testResult = $whatsappService->sendTemplateMessage(
                    $testPayload['contact']->uuid,
                    $testPayload['templateContent'],
                    $this->createdBy,
                    $this->campaignId,
                    null,
                    $testPayload['contact'],
                    $campaign,
                    $template
                );
                Log::channel('campaign')->info('CampaignChunkJob FLOW template sendTemplateMessage test', [
                    'campaign_id' => $this->campaignId,
                    'template_name' => $testPayload['templateContent']['name'] ?? null,
                    'success' => $testResult->success ?? false,
                    'phone_number_id' => $whatsappService->getPhoneNumberIdProperty(),
                ]);
            } catch (\Exception $e) {
                Log::channel('campaign')->error('CampaignChunkJob FLOW template sendTemplateMessage test FAILED', [
                    'campaign_id' => $this->campaignId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($isLoadTest) {
            $results = [];
            foreach ($payloads as $idx => $payload) {
                $results[$idx] = (object) [
                    'success' => true,
                    'data' => (object) [
                        'messages' => [(object) ['id' => 'test_wam_' . $payload['log']->id, 'message_status' => 'sent']],
                    ],
                ];
            }
        } else {
            $results = $this->sendConcurrently($payloads, $whatsappService);
        }

        // 9. Process results: create Chat/ChatLog records, batch-update CampaignLog statuses
        $successIds = [];
        $failedUpdates = [];

        foreach ($payloads as $idx => $payload) {
            $log = $payload['log'];
            $contact = $payload['contact'];
            $result = $results[$idx] ?? null;

            if (!$result) {
                $failedUpdates[] = [
                    'id' => $log->id,
                    'status' => 'failed',
                    'metadata' => json_encode(['success' => false, 'message' => 'No response from API']),
                    'updated_at' => now(),
                ];
                continue;
            }

            if ($result->success === true) {
                try {
                    $campaignMetadata = json_decode($campaign->metadata);

                    if ($isCarousel) {
                        $chatMetadata = $whatsappService->buildCarouselTemplateChatMessage(
                            $contact->uuid,
                            $template->id,
                            $template
                        );
                    } else {
                        $chatMetadata = $whatsappService->buildCampaignTemplateChatMessage(
                            $campaignMetadata,
                            $contact->uuid,
                            $contact
                        );
                    }

                    $mediaId = $whatsappService->getMediaIdFromCampaign($this->campaignId);

                    $created = $whatsappService->createChatLogFromResponse(
                        $result,
                        $contact,
                        $this->createdBy,
                        $this->organizationId,
                        $chatMetadata,
                        $mediaId,
                        $this->campaignId,
                        $payload['flowStubs']
                    );

                    $chat = $created['chat'];

                    $successIds[] = [
                        'id' => $log->id,
                        'chat_id' => $chat->id,
                        'status' => 'success',
                        'metadata' => $this->cleanResponseMetadata($result),
                        'updated_at' => now(),
                    ];
                } catch (\Exception $e) {
                    Log::error('Error creating chat log for campaign message', [
                        'log_id' => $log->id,
                        'error' => $e->getMessage(),
                    ]);
                    $successIds[] = [
                        'id' => $log->id,
                        'status' => 'success',
                        'metadata' => json_encode(['success' => true, 'chat_creation_error' => $e->getMessage()]),
                        'updated_at' => now(),
                    ];
                }
            } else {
                $failedUpdates[] = [
                    'id' => $log->id,
                    'status' => 'failed',
                    'metadata' => json_encode($result->data ?? ['error' => ['message' => $result->message ?? 'Unknown error']]),
                    'updated_at' => now(),
                ];
            }
        }

        // Batch update successes
        foreach ($successIds as $update) {
            $id = $update['id'];
            unset($update['id']);
            CampaignLog::where('id', $id)->update($update);
        }

        // Batch update failures
        foreach ($failedUpdates as $update) {
            $id = $update['id'];
            unset($update['id']);
            CampaignLog::where('id', $id)->update($update);
        }

        // 10. Broadcast progress once per chunk
        $this->broadcastProgressIfNeeded();

        // 11. Check campaign completion once per chunk
        $this->checkAndUpdateCampaignStatus();

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::channel('campaign')->info('ProcessCampaignChunkJob completed', [
            'campaign_id' => $this->campaignId,
            'chunk_size' => count($this->logIds),
            'duration_ms' => $duration,
            'success_count' => count($successIds),
            'failed_count' => count($failedUpdates),
        ]);
    }

    protected function sendConcurrently(array $payloads, WhatsappService $whatsappService): array
    {
        $results = [];
        $client = WhatsappService::getSharedClient();
        $url = $whatsappService->getSendUrl();
        $headers = $whatsappService->setHeaders();

        // Debug: log first payload to verify request structure
        if (!empty($payloads)) {
            $firstPayload = $payloads[0]['requestData'] ?? null;
            Log::channel('campaign')->info('CampaignChunkJob first request payload', [
                'campaign_id' => $this->campaignId,
                'organization_id' => $this->organizationId,
                'url' => $url,
                'phone_number_id' => $whatsappService->getPhoneNumberIdProperty(),
                'payload_template' => $firstPayload['template'] ?? null,
                'total_payloads' => count($payloads),
            ]);
        }

        $delayMs = $this->requestDelayMs;

        $requests = function () use ($payloads, $url, $headers, $delayMs) {
            foreach ($payloads as $idx => $payload) {
                if ($idx > 0 && $delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                yield $idx => new Request(
                    'POST',
                    $url,
                    $headers,
                    json_encode($payload['requestData'])
                );
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => $this->concurrency,
            'fulfilled' => function ($response, $index) use (&$results) {
                $responseObject = new \stdClass();
                $body = json_decode($response->getBody()->getContents());

                // Meta can return HTTP 200 with an error object in the body
                // (e.g. rate limit 130429). Treat these as failures.
                if (isset($body->error)) {
                    $responseObject->success = false;
                    $responseObject->data = $body;
                    $responseObject->message = $body->error->error_user_msg
                        ?? $body->error->message
                        ?? 'Unknown error';
                } else {
                    $responseObject->success = true;
                    $responseObject->data = $body;
                }

                $results[$index] = $responseObject;
            },
            'rejected' => function ($reason, $index) use (&$results, $payloads) {
                $responseObject = new \stdClass();
                $responseObject->success = false;

                // Debug: log failed request details
                $failedPayload = $payloads[$index]['requestData'] ?? null;
                Log::channel('campaign')->error('CampaignChunkJob request rejected', [
                    'campaign_id' => $this->campaignId ?? null,
                    'organization_id' => $this->organizationId ?? null,
                    'index' => $index,
                    'template_name' => $failedPayload['template']['name'] ?? null,
                    'template_language' => $failedPayload['template']['language'] ?? null,
                    'phone' => $failedPayload['to'] ?? null,
                    'error_class' => get_class($reason),
                    'error_message' => $reason->getMessage(),
                ]);

                if ($reason instanceof ConnectException) {
                    $responseObject->data = new \stdClass();
                    $responseObject->data->error = new \stdClass();
                    $responseObject->data->error->message = $reason->getMessage();
                    $responseObject->message = $reason->getMessage();
                } elseif ($reason instanceof GuzzleException && method_exists($reason, 'getResponse') && $reason->getResponse()) {
                    $response = $reason->getResponse();
                    $responseObject->data = json_decode($response->getBody()->getContents());
                    $responseObject->message = $responseObject->data->error->error_user_msg
                        ?? $responseObject->data->error->message
                        ?? 'Unknown error';
                } else {
                    $responseObject->data = new \stdClass();
                    $responseObject->data->error = new \stdClass();
                    $responseObject->data->error->message = $reason->getMessage();
                    $responseObject->message = $reason->getMessage();
                }

                $results[$index] = $responseObject;
            },
        ]);

        $pool->promise()->wait();

        return $results;
    }

    protected function getCampaignCached(): ?Campaign
    {
        if (!isset(self::$campaignCache[$this->campaignId])) {
            self::$campaignCache[$this->campaignId] = Campaign::find($this->campaignId);
        }
        return self::$campaignCache[$this->campaignId];
    }

    protected function getTemplateCached(): ?Template
    {
        if (!isset(self::$templateCache[$this->templateId])) {
            self::$templateCache[$this->templateId] = Template::find($this->templateId);
        }
        return self::$templateCache[$this->templateId];
    }

    protected function getWhatsappService(): WhatsappService
    {
        if (!isset(self::$whatsappServiceCache[$this->organizationId])) {
            $config = OrganizationService::getCachedMetadata($this->organizationId);
            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = 'v18.0';
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            Log::channel('campaign')->info('CampaignChunkJob creating WhatsappService', [
                'organization_id' => $this->organizationId,
                'campaign_id' => $this->campaignId,
                'phone_number_id' => $phoneNumberId,
                'waba_id' => $wabaId,
                'access_token_prefix' => substr($accessToken ?? '', 0, 10) . '...',
                'cached_services_count' => count(self::$whatsappServiceCache),
            ]);

            self::$whatsappServiceCache[$this->organizationId] = new WhatsappService(
                $accessToken,
                $apiVersion,
                $appId,
                $phoneNumberId,
                $wabaId,
                $this->organizationId
            );
        } else {
            Log::channel('campaign')->info('CampaignChunkJob reusing cached WhatsappService', [
                'organization_id' => $this->organizationId,
                'campaign_id' => $this->campaignId,
                'cached_phone_number_id' => self::$whatsappServiceCache[$this->organizationId]->getPhoneNumberIdProperty(),
                'cached_services_count' => count(self::$whatsappServiceCache),
            ]);
        }

        return self::$whatsappServiceCache[$this->organizationId];
    }

    protected function broadcastProgressIfNeeded(): void
    {
        try {
            $stats = CampaignLog::where('campaign_id', $this->campaignId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) as ongoing
                ")
                ->first();

            $isComplete = $stats->pending == 0 && $stats->ongoing == 0;

            $state = self::$progressState[$this->campaignId] ?? [
                'last_broadcast' => 0,
                'messages_since' => 0,
            ];
            $state['messages_since'] += count($this->logIds);
            $now = time();

            if ($isComplete || $state['messages_since'] >= 10 || ($now - $state['last_broadcast']) >= 5) {
                $campaign = $this->getCampaignCached();
                if (!$campaign) return;

                $percentComplete = $stats->total > 0
                    ? round(($stats->sent + $stats->failed) / $stats->total * 100, 1)
                    : 0;

                event(new CampaignProgressEvent(
                    campaignUuid: $campaign->uuid,
                    total: (int) $stats->total,
                    sent: (int) $stats->sent,
                    failed: (int) $stats->failed,
                    pending: (int) $stats->pending,
                    percentComplete: $percentComplete,
                ));

                self::$progressState[$this->campaignId] = [
                    'last_broadcast' => $now,
                    'messages_since' => 0,
                ];
            } else {
                self::$progressState[$this->campaignId] = $state;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast campaign progress: ' . $e->getMessage());
        }
    }

    protected function checkAndUpdateCampaignStatus(): void
    {
        $campaign = $this->getCampaignCached();
        if (!$campaign || $campaign->status !== 'ongoing') {
            return;
        }

        $pendingOrOngoing = CampaignLog::where('campaign_id', $this->campaignId)
            ->whereIn('status', ['pending', 'ongoing'])
            ->exists();

        if ($pendingOrOngoing) {
            return;
        }

        $orgMetadata = OrganizationService::getCachedMetadata($this->organizationId);
        $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
        $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
        $maxRetries = count($retryIntervals);

        $hasRetriableLogs = false;

        if ($retryEnabled) {
            $failedLogs = CampaignLog::where('campaign_id', $this->campaignId)
                ->where('status', 'failed')
                ->withCount('retries')
                ->get();

            foreach ($failedLogs as $log) {
                if ($log->retries_count < $maxRetries) {
                    $hasRetriableLogs = true;
                    $delayMinutes = $retryIntervals[$log->retries_count] ?? null;
                    if ($delayMinutes !== null) {
                        RetryCampaignLogJob::dispatch($this->organizationId, $log->id, $log->retries_count)
                            ->onQueue('campaign-messages')
                            ->delay(now()->addMinutes($delayMinutes));
                    }
                }
            }
        }

        if (!$hasRetriableLogs) {
            $campaign->update(['status' => 'completed']);
            Log::info("Campaign {$this->campaignId} marked as completed.");
        }
    }

    private function cleanResponseMetadata(object $result): string
    {
        $cleaned = clone $result;
        unset($cleaned->success);
        if (property_exists($cleaned, 'data') && property_exists($cleaned->data, 'chat')) {
            unset($cleaned->data->chat);
        }
        return json_encode($cleaned);
    }

    public static function resetProgressState(?int $campaignId = null): void
    {
        if ($campaignId === null) {
            self::$progressState = [];
            self::$campaignCache = [];
            self::$templateCache = [];
            self::$whatsappServiceCache = [];
        } else {
            unset(self::$progressState[$campaignId]);
            unset(self::$campaignCache[$campaignId]);
        }
    }
}
