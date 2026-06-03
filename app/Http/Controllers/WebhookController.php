<?php

namespace App\Http\Controllers;

use Str;
use App\Models\Chat;
use GuzzleHttp\Client;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Template;
use App\Models\ChatMedia;
use App\Events\NewChatEvent;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Models\ChatStatusLog;
use App\Services\ChatService;
use App\Helpers\WebhookHelper;
use App\Services\PhoneService;
use App\Services\StripeService;
use App\Services\AutoReplyService;
use Illuminate\Support\Facades\Log;
use App\Services\UnsubscribeChecker;
use App\Services\SubscriptionService;
use App\Models\MissedChatStatusUpdate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use GuzzleHttp\Exception\GuzzleException;
use App\Resolvers\PaymentPlatformResolver;
use GuzzleHttp\Exception\ConnectException;
use Propaganistas\LaravelPhone\PhoneNumber;
use App\Http\Controllers\Controller as BaseController;
use App\Jobs\Webhook\ProcessWebhookJob;
use App\Jobs\Webhook\StoreWhatsappWebhookPayloadSampleJob;

class WebhookController extends BaseController
{
    protected $paymentPlatformResolver;
    protected $checker;

    public function __construct()
    {
        $this->paymentPlatformResolver = new PaymentPlatformResolver();
        $this->checker = new UnsubscribeChecker();

        Config::set('broadcasting.connections.pusher', [
            'driver' => 'pusher',
            'key' => Setting::where('key', 'pusher_app_key')->value('value'),
            'secret' => Setting::where('key', 'pusher_app_secret')->value('value'),
            'app_id' => Setting::where('key', 'pusher_app_id')->value('value'),
            'options' => [
                'cluster' => Setting::where('key', 'pusher_app_cluster')->value('value'),
            ],
        ]);
    }

    public function whatsappWebhook(Request $request){
        
        Log::channel('waba_webhook')->info('Incoming WABA webhook payload', [
            'payload' => $request->all()
        ]); 

        $verifyToken = Setting::where('key', 'whatsapp_callback_token')->first()->value;

        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return $this->challengeResponse($challenge);
        } else {
            return Response::json(['error' => 'Forbidden'], 200);
        }
    }

    public function handle(Request $request, $identifier = null)
    {
        $organization = $this->getOrganizationByIdentifier($identifier);

        if (!$organization) {
            return $this->forbiddenResponse();
        }

        return $this->handleMethod($request, $organization);
    }

    protected function getOrganizationByIdentifier($identifier)
    {
        return Organization::where('identifier', $identifier)->first();
    }

    protected function handleMethod(Request $request, Organization $organization)
    {
        if ($request->isMethod('get')) {
            return $this->handleGetRequest($request, $organization);
        } elseif ($request->isMethod('post')) {
            $metadata = json_decode($organization->metadata);

            if (empty($metadata)) {
                return $this->forbiddenResponse();
            }

            // Signature validation - executes BEFORE any job dispatch (AC-1.1.2)
            $appSecret = $metadata->whatsapp->app_secret ?? null;
            if ($appSecret) {
                $headerSignature = $request->header('X-Hub-Signature-256');
                $payload = $request->getContent();
                $calculatedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

                if (!$this->isValidSignature($calculatedSignature, $headerSignature)) {
                    return $this->invalidSignatureResponse();
                }
            }

            return $this->handlePostRequest($request, $organization);
        }

        return Response::json(['error' => 'Method Not Allowed'], 405);
    }

    protected function forbiddenResponse()
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }

    protected function isValidSignature($calculatedSignature, $headerSignature)
    {
        return hash_equals($calculatedSignature, $headerSignature);
    }

    protected function invalidSignatureResponse()
    {
        return Response::json(['status' => 'error', 'message' => __('Invalid payload signature')], 400);
    }

    protected function handleGetRequest(Request $request, Organization $organization)
    {
        try {
            $verifyToken = $organization->identifier;

            $mode = $request->input('hub_mode');
            $token = $request->input('hub_verify_token');
            $challenge = $request->input('hub_challenge');

            if ($mode === 'subscribe' && $token === $verifyToken) {
                return $this->challengeResponse($challenge);
            } else {
                return Response::json(['error' => 'Forbidden'], 404);
            }
        } catch (\Exception $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => $e->getMessage()], 403);
        }
    }

    /**
     * Meta webhook verification requires exact raw challenge body.
     * Clear any buffered output and strip UTF-8 BOM to avoid code 2201 mismatch.
     */
    protected function challengeResponse($challenge)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $challengeValue = ltrim((string) $challenge, "\xEF\xBB\xBF");

        return response($challengeValue, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    /**
     * Handle POST webhook requests - Immediate 200 OK Pattern (ADR-001).
     *
     * This method validates the webhook and dispatches processing to a background job,
     * returning HTTP 200 within 200ms to prevent Meta timeouts.
     *
     * @param Request $request The incoming webhook request
     * @param Organization $organization The target organization
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handlePostRequest(Request $request, Organization $organization)
    {
        // Start timing for monitoring (AC-1.1.6)
        $startTime = microtime(true);
        $payload = $request->all();

        $this->storeReceivedWhatsappWebhookForDebugging($payload, $organization->id);

        // Extract webhook field type from payload
        $entry = $payload['entry'] ?? [];
        if (empty($entry) || !isset($entry[0]['changes'][0])) {
            Log::channel('webhook')->warning('Invalid webhook payload structure', [
                'organization_id' => $organization->id,
            ]);
            return Response::json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $this->storeMultiObjectWebhookPayloadSample(
            $payload,
            $organization->id
        );

        $res = $entry[0]['changes'][0];
        $webhookType = $res['field'] ?? 'unknown';

        // Dispatch to background job for async processing (AC-1.1.3, AC-1.1.4, AC-1.1.5)
        // No database writes or external API calls occur here
        ProcessWebhookJob::dispatch(
            $res,
            $organization->id,
            $webhookType
        );

        
        // this code to test job sync without queue
        /*$job = new ProcessWebhookJob($res, $organization->id, $webhookType);
        $job->handle();*/
        

        // Calculate response time and log (AC-1.1.6)
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        Log::channel('webhook')->info('Webhook received and queued', [
            'organization_id' => $organization->id,
            'webhook_type' => $webhookType,
            'response_time_ms' => round($responseTime, 2),
            'timestamp' => now()->toIso8601String(),
        ]);

        // Return HTTP 200 immediately (AC-1.1.1)
        return Response::json(['status' => 'success'], 200);
    }

    /**
     * Temporarily store every received WhatsApp webhook for organization 29.
     *
     * This runs before payload validation and before queue dispatch so we can
     * prove whether Meta reached this application even when processing fails.
     */
    protected function storeReceivedWhatsappWebhookForDebugging(array $payload, int $organizationId): void
    {
        if ($organizationId !== 29) {
            return;
        }

        try {
            $summary = $this->summarizeWhatsappWebhookPayload($payload);
            $payloadHash = hash('sha256', json_encode($payload));

            DB::table('whatsapp_webhook_payload_samples')->insertOrIgnore([
                'organization_id' => $organizationId,
                'webhook_type' => $summary['webhook_type'],
                'entry_count' => $summary['entry_count'],
                'changes_count' => $summary['changes_count'],
                'message_count' => $summary['message_count'],
                'status_count' => $summary['status_count'],
                'echo_count' => $summary['echo_count'],
                'phone_number_id' => $summary['phone_number_id'],
                'sender_phone' => $summary['sender_phone'],
                'sender_phone_numbers' => json_encode($summary['sender_phone_numbers']),
                'wam_ids' => json_encode($summary['wam_ids']),
                'payload_hash' => $payloadHash,
                'payload' => json_encode($this->redactSensitiveWebhookPayload($payload)),
                'received_at' => now(),
            ]);

            Log::channel('webhook')->info('WhatsApp webhook stored for organization 29 debugging', [
                'organization_id' => $organizationId,
                'webhook_type' => $summary['webhook_type'],
                'message_count' => $summary['message_count'],
                'status_count' => $summary['status_count'],
                'echo_count' => $summary['echo_count'],
                'payload_hash' => $payloadHash,
            ]);
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to store WhatsApp webhook for organization 29 debugging', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function summarizeWhatsappWebhookPayload(array $payload): array
    {
        $entries = $payload['entry'] ?? [];
        $entryCount = is_array($entries) ? count($entries) : 0;
        $changesCount = 0;
        $messageCount = 0;
        $statusCount = 0;
        $echoCount = 0;
        $phoneNumberId = null;
        $senderPhoneNumbers = [];
        $wamIds = [];

        if (! is_array($entries)) {
            $entries = [];
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            $changesCount += count($changes);

            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $phoneNumberId = $phoneNumberId ?: ($value['metadata']['phone_number_id'] ?? null);

                $messages = is_array($value['messages'] ?? null) ? $value['messages'] : [];
                $statuses = is_array($value['statuses'] ?? null) ? $value['statuses'] : [];
                $messageEchoes = is_array($value['message_echoes'] ?? null) ? $value['message_echoes'] : [];

                $messageCount += count($messages);
                $statusCount += count($statuses);
                $echoCount += count($messageEchoes);

                foreach (array_merge($messages, $statuses, $messageEchoes) as $item) {
                    if (! empty($item['id'])) {
                        $wamIds[] = $item['id'];
                    }
                }

                foreach ($messages as $message) {
                    if (! empty($message['from'])) {
                        $senderPhoneNumbers[] = $message['from'];
                    }
                }

                $contacts = is_array($value['contacts'] ?? null) ? $value['contacts'] : [];
                foreach ($contacts as $contact) {
                    if (! empty($contact['wa_id'])) {
                        $senderPhoneNumbers[] = $contact['wa_id'];
                    }
                }
            }
        }

        $senderPhoneNumbers = array_values(array_unique(array_slice($senderPhoneNumbers, 0, 100)));

        return [
            'webhook_type' => $entries[0]['changes'][0]['field'] ?? 'unknown',
            'entry_count' => $entryCount,
            'changes_count' => $changesCount,
            'message_count' => $messageCount,
            'status_count' => $statusCount,
            'echo_count' => $echoCount,
            'phone_number_id' => $phoneNumberId,
            'sender_phone' => $senderPhoneNumbers[0] ?? null,
            'sender_phone_numbers' => $senderPhoneNumbers,
            'wam_ids' => array_values(array_unique(array_slice($wamIds, 0, 100))),
        ];
    }

    protected function redactSensitiveWebhookPayload($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveWebhookPayloadKey($key)) {
                $redacted[$key] = '[REDACTED]';
                continue;
            }

            $redacted[$key] = $this->redactSensitiveWebhookPayload($item);
        }

        return $redacted;
    }

    protected function isSensitiveWebhookPayloadKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, ['authorization', 'access_token', 'app_secret', 'client_secret'], true)
            || $normalized === 'token'
            || str_ends_with($normalized, '_token');
    }

    /**
     * Store diagnostic samples only when Meta sends a multi-object webhook payload.
     *
     * This intentionally does not change processing behavior: handlePostRequest
     * still dispatches only entry[0].changes[0] after this diagnostic capture.
     */
    protected function storeMultiObjectWebhookPayloadSample(array $payload, int $organizationId): void
    {
        try {
            if (! $this->isMultiObjectWhatsappWebhookPayload($payload)) {
                return;
            }

            app()->terminating(function () use ($organizationId, $payload) {
                try {
                    StoreWhatsappWebhookPayloadSampleJob::dispatch($organizationId, $payload);
                } catch (\Throwable $e) {
                    Log::channel('webhook')->warning('Failed to dispatch multi-object WhatsApp webhook payload sample job', [
                        'organization_id' => $organizationId,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Log::channel('webhook')->warning('Failed to queue multi-object WhatsApp webhook payload sample', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function isMultiObjectWhatsappWebhookPayload(array $payload): bool
    {
        $entries = $payload['entry'] ?? [];
        if (! is_array($entries)) {
            return false;
        }

        if (count($entries) > 1) {
            return true;
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry['changes'] ?? null) ? $entry['changes'] : [];
            if (count($changes) > 1) {
                return true;
            }

            foreach ($changes as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                foreach (['messages', 'statuses', 'message_echoes'] as $key) {
                    if (is_array($value[$key] ?? null) && count($value[$key]) > 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function downloadMedia($mediaInfo, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
        }

        try {
            $client = new Client();

            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', $mediaInfo['url'], $requestOptions);

            $fileContent = $response->getBody();
            $mimeType = $mediaInfo['mime_type'] ?? 'application/octet-stream'; // Default fallback
            $fileName = $this->generateFilename($fileContent, $mediaInfo['mime_type']);

            $storage = Setting::where('key', 'storage_system')->first()->value;

            if($storage === 'local'){
                $location = 'local';
                $file = Storage::disk('local')->put('public/' . $fileName, $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . 'public/' . $fileName;
            } else if($storage === 'aws') {
                $location = 'amazon';
                $filePath = 'uploads/media/received/'  . $organization->id . '/' . Str::random(40) . time();
                $file = Storage::disk('s3')->put($filePath, $fileContent, [
                    'ContentType' => $mimeType
                ]);
                $mediaUrl = Storage::disk('s3')->url($filePath);
            }

            $mediaData = [
                'media_url' => $mediaUrl,
                'location' => $location,
            ];
    
            return $mediaData;
        } catch (RequestException $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => 'Failed to download file'], 403);
        }
    }

    private function generateFilename($fileContent, $mimeType)
    {
        // Generate a unique filename based on the file content
        $hash = sha1($fileContent);

        // Get the file extension from the media type
        $extension = explode('/', $mimeType)[1];

        // Combine the hash, timestamp, and extension to create a unique filename
        $filename = "{$hash}_" . time() . ".{$extension}";

        return $filename;
    }

    private function messageIsUnSubscribe($request, $organization, $contact)
    {
        try {
         $payload = data_get($request, 'entry.0.changes.0.value.messages.0.button.text');

        if ($payload) {
            $this->checker->check($organization, $payload, $contact->id);
        }

    } catch (\Exception $e) {}

    }

    private function getMedia($mediaId, Organization $organization)
    {
        $metadata = json_decode($organization->metadata);

        if (empty($metadata) || empty($metadata->whatsapp->access_token)) {
            return $this->forbiddenResponse();
        }

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $requestOptions = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $metadata->whatsapp->access_token,
                    'Content-Type' => 'application/json',
                ],
            ];

            $response = $client->request('GET', "https://graph.facebook.com/v18.0/{$mediaId}", $requestOptions);

            return json_decode($response->getBody()->getContents(), true);
        } catch (Exception $e) {
            return Response::json(['error' => 'Method Invalid'], 400);
        }
    }

    public function processWebhook(Request $request, $processor)
    {
        $paymentPlatform = $this->paymentPlatformResolver->resolveService($processor);
        session()->put('paymentPlatform', $processor);
        
        return $paymentPlatform->handleWebhook($request);
    }
}
