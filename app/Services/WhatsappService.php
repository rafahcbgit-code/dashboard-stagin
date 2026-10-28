<?php

namespace App\Services;

use App\Events\NewChatEvent;
use App\Helpers\WebhookHelper;
use App\Models\Campaign;
use App\Models\Chat;
use App\Models\ChatLog;
use App\Models\ChatMedia;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Template;
use App\Services\Meta\WhatsApp\TemplateStatusNotificationRecorder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\MediaUploadService;
use Session;

class WhatsappService
{
    private $accessToken;
    private $apiVersion;
    private $appId;
    private $phoneNumberId;
    private $organizationId;
    private $wabaId;

    /** @var Client|null Shared Guzzle client for concurrent requests within a single PHP process */
    private static ?Client $sharedClient = null;

    public function __construct($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId)
    {
        $this->accessToken = $accessToken;
        $this->apiVersion = $apiVersion;
        $this->appId = $appId;
        $this->phoneNumberId = $phoneNumberId;
        $this->wabaId = $wabaId;
        $this->organizationId = $organizationId;

        // Cache Pusher config to avoid 4 DB queries per WhatsappService instantiation
        $pusherConfig = \Illuminate\Support\Facades\Cache::remember('pusher_broadcast_config', 3600, function () {
            return [
                'driver' => 'pusher',
                'key' => Setting::where('key', 'pusher_app_key')->first()->value ?? '',
                'secret' => Setting::where('key', 'pusher_app_secret')->first()->value ?? '',
                'app_id' => Setting::where('key', 'pusher_app_id')->first()->value ?? '',
                'options' => [
                    'cluster' => Setting::where('key', 'pusher_app_cluster')->first()->value ?? 'mt1',
                ],
            ];
        });
        Config::set('broadcasting.connections.pusher', $pusherConfig);
    }

    public static function getSharedClient(): Client
    {
        if (self::$sharedClient === null) {
            self::$sharedClient = new Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
        }
        return self::$sharedClient;
    }

    public static function resetSharedClient(): void
    {
        self::$sharedClient = null;
    }

    public static function setSharedClientForTesting(Client $client): void
    {
        self::$sharedClient = $client;
    }

    public function getApiVersion(): string
    {
        return $this->apiVersion;
    }

    public function getPhoneNumberIdProperty(): string
    {
        return $this->phoneNumberId;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getSendUrl(): string
    {
        return "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
    }

    public function buildTemplateRequestPayload(string $phone, array $templateContent, ?int $campaignId = null): array
    {
        $requestData = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $phone,
            'type' => 'template',
            'template' => $templateContent,
        ];

        return $requestData;
    }

    /**
     * This function sends a text message via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $messageContent The content of the message to be sent.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendMessage($contactUuId, $messageContent, $userId = NULL, $type="text", $buttons = [], $header = [], $footer = null, $buttonLabel = null, ?Chat $replyToMessage = null)
    {
        $contact = Contact::where('uuid', $contactUuId)->first();

        if (! $contact) {
            return $this->buildErrorResponse('Contact not found');
        }

        if ((bool) ($contact->is_blocked ?? false)) {
            return $this->buildBlockedContactResponse($contact);
        }

        $subscriptionGuard = $this->guardExpiredSubscription((int) $contact->organization_id);
        if ($subscriptionGuard !== null) {
            return $subscriptionGuard;
        }

        $conversationGuard = $this->guardConversationLimit((int) $contact->organization_id);
        if ($conversationGuard !== null) {
            return $conversationGuard;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        if ($replyToMessage) {
            $requestData['context'] = [
                'message_id' => $replyToMessage->wam_id,
            ];
        }
        if($type == "text"){
            $requestData['type'] = 'text';
            $requestData['text']['preview_url'] = true; //If you have added url either http or https a preview will be displayed
            $requestData['text']['body'] = clean($messageContent);
        } else if($type == "interactive buttons" || $type == "interactive call to action url" || $type == "interactive list"){
            $requestData['type'] = 'interactive';

            if($type == "interactive buttons"){
                $requestData['interactive']['type'] = 'button';
            } else if($type == "interactive call to action url"){
                $requestData['interactive']['type'] = 'cta_url';
            } else if($type == "interactive list"){
                $requestData['interactive']['type'] = 'list';
            }

            if($type == "interactive buttons"){
                foreach($buttons as $button){
                    $requestData['interactive']['action']['buttons'][] = [
                        'type' => 'reply',
                        'reply' => [
                            'id' => $button['id'],
                            'title' => $button['title'],
                        ],
                    ];
                }
            } else if($type == "interactive call to action url"){
                $requestData['interactive']['action']['name'] = "cta_url";
                $requestData['interactive']['action']['parameters'] = $buttons;
            } else if($type == "interactive list"){
                $requestData['interactive']['action']['sections'] = $buttons;
                $requestData['interactive']['action']['button'] = $buttonLabel;
            }

            if (!empty($header)) {
                $requestData['interactive']['header'] = $header;
            }

            $requestData['interactive']['body']['text'] = clean($messageContent);

            if ($footer != null) {
                $requestData['interactive']['footer'] = [
                    'text' => clean($footer),
                ];
            }
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        if($responseObject->success === true){
            $response = [];

            if (($requestData['type'] ?? 'text') === 'interactive') {
                $response['type'] = 'interactive';
                $response['interactive'] = $requestData['interactive'] ?? [];
            } else {
                $response['type'] = 'text';
                $response['text']['body'] = clean($messageContent);
            }

            if ($replyToMessage) {
                $response['context'] = [
                    'id' => $replyToMessage->wam_id,
                    'message_id' => $replyToMessage->wam_id,
                ];
            }

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $responseObject->data->messages[0]->id,
                'reply_to_message_id' => $replyToMessage?->id,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $userId,
                'metadata' => json_encode($response),
                'status' => 'delivered',
                'created_at' => now(),
            ]);

            $this->recordConversationWindowForOutbound($chat, $contact);

            $chat = Chat::with('contact','media', 'replyTo')->where('id', $chat->id)->first();
            $responseObject->data->chat = $chat;

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' => now()
            ]);

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);
            
            event(new NewChatEvent($chatArray, $contact->organization_id));
        }

        // A failed provider request must never be advertised to integrations
        // as message.sent.
        if (($responseObject->success ?? false) === true) {
            WebhookHelper::triggerWebhookEvent('message.sent', [
                'data' => $responseObject,
            ], $contact->organization_id);
        }

        return $responseObject;
    }

    /**
     * This function sends a text message via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $messageContent The content of the message to be sent.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendTemplateMessage($contactUuId, $templateContent, $userId = NULL, $campaignId = NULL, $mediaId = NULL, ?Contact $contact = null, ?Campaign $campaign = null, ?Template $campaignTemplate = null, ?string $billingSource = null, ?int $billingSourceId = null)
    {
        $contact = $contact ?? Contact::where('uuid', $contactUuId)->first();

        if (! $contact) {
            return $this->buildErrorResponse('Contact not found');
        }

        if ((bool) ($contact->is_blocked ?? false)) {
            return $this->buildBlockedContactResponse($contact);
        }

        $subscriptionGuard = $this->guardExpiredSubscription((int) $contact->organization_id);
        if ($subscriptionGuard !== null) {
            return $subscriptionGuard;
        }

        $conversationGuard = $this->guardConversationLimit((int) $contact->organization_id);
        if ($conversationGuard !== null) {
            return $conversationGuard;
        }

        $templateMessagesGuard = $this->guardTemplateMessagesLimit((int) $contact->organization_id);
        if ($templateMessagesGuard !== null) {
            return $templateMessagesGuard;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        // Strip internal FLOW stubs before building the Meta API payload
        $flowStubs = $templateContent['_flow_stubs'] ?? [];
        unset($templateContent['_flow_stubs']);

        $templateModel = $campaignTemplate;
        if (!$templateModel && $campaignId !== null) {
            $campaign = $campaign ?? Campaign::find($campaignId);
            $templateModel = $campaign ? Template::find($campaign->template_id) : null;
        }
        if (!$templateModel) {
            $templateModel = $this->resolveTemplateFromPayload($templateContent);
        }

        $displayHeaderMedia = $this->extractTemplateHeaderMediaForDisplay($templateContent, $templateModel);

        try {
            $templateContent = $this->enrichTemplateHeaderWithMediaId(
                $templateContent,
                $templateModel,
                [
                    'campaign_id' => $campaignId,
                    'template_id' => $templateModel?->id,
                    'contact_id' => $contact->id,
                ]
            );
        } catch (\RuntimeException $e) {
            Log::error('Template media enrichment failed before Meta send', [
                'error' => $e->getMessage(),
                'campaign_id' => $campaignId,
                'template_id' => $templateModel?->id,
                'contact_id' => $contact->id,
                'template' => $templateContent['name'] ?? null,
            ]);

            return $this->buildErrorResponse($e->getMessage(), 422, 'TEMPLATE_MEDIA_UPLOAD_FAILED');
        }

        if ($this->isCarouselPayload($templateContent)) {
            $templateContent = $this->enrichCarouselWithMediaIds($templateContent, $campaignId);

            if ($this->carouselPayloadHasMediaLinks($templateContent)) {
                Log::error('Carousel media enrichment left unresolved media links', [
                    'campaign_id' => $campaignId,
                    'template_id' => $templateModel?->id,
                    'contact_id' => $contact->id,
                    'template' => $templateContent['name'] ?? null,
                ]);

                return $this->buildErrorResponse(
                    __('Unable to upload carousel media before sending.'),
                    422,
                    'CAROUSEL_MEDIA_UPLOAD_FAILED'
                );
            }
        }

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = 'template';
        $requestData['template'] = $templateContent;

        
        Log::info('Template variables payload', [
            'template' => $templateContent['name'] ?? null,
            'contact_id' => $contact->id ?? null,
            'variables' => $this->extractTemplateVariables($templateContent),
        ]);

        Log::info('WhatsApp API Request: ' . json_encode($requestData));

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);
        Log::info("meta resp", [$responseObject]);

        if (($responseObject->success ?? false) !== true && $this->templatePayloadHasHeaderMedia($templateContent)) {
            Log::error('Template media send failed after Meta request', [
                'campaign_id' => $campaignId,
                'template_id' => $templateModel?->id,
                'contact_id' => $contact->id,
                'wamid' => $responseObject->data->messages[0]->id ?? null,
                'error' => $responseObject->data->error ?? null,
            ]);
        }

        if($responseObject->success === true){
            // Determine metadata based on template type
            $metadata = null;

            if ($campaignId != NULL) {
                // Campaign flow
                $campaign = $campaign ?? Campaign::find($campaignId);
                $campaignMetadata = json_decode($campaign->metadata);

                $template = $campaignTemplate ?? Template::find($campaign->template_id);
                $templateMeta = json_decode($template->metadata, true);
                
                logger($templateMeta);

                if (($templateMeta['template_type'] ?? '') === 'carousel') {
                    // Carousel campaign
                    $metadata = $this->buildCarouselTemplateChatMessage($contactUuId, $template->id, $template);
                } else {
                    // Standard campaign
                    $metadata = $this->buildCampaignTemplateChatMessage($campaignMetadata, $contactUuId, $contact, $displayHeaderMedia);
                }
            } else {
                // Direct send flow
                if (isset($templateContent['components']) && $this->isCarouselPayload($templateContent)) {
                    // Carousel direct send
                    $templateId = $this->resolveTemplateIdFromPayload($templateContent);
                    $metadata = $this->buildCarouselTemplateChatMessage($contactUuId, $templateId);
                } else {
                    // Standard direct send
                    $metadata = $this->buildTemplateChatMessage($templateContent, $contact, $displayHeaderMedia);
                }
            }

            $metadata = $this->attachTemplateBillingMetadata($metadata, $campaignId, $billingSource, $billingSourceId);

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $responseObject->data->messages[0]->id,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $userId,
                'metadata' => $metadata,
                'media_id' => $campaignId != NULL ? $this->getMediaIdFromCampaign($campaignId) : $mediaId,
                'status' => isset($responseObject->data->messages[0]->message_status) ? $responseObject->data->messages[0]->message_status : 'sent',
                'created_at' => now()
            ]);

            $this->recordConversationWindowForOutbound($chat, $contact);

            $responseObject->data->chat = $chat;

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' => now()
            ]);

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);

            event(new NewChatEvent($chatArray, $contact->organization_id));

            // Pre-create MetaFlowResponse stubs for any FLOW buttons in the template.
            // This mirrors what MetaFlowService::sendFlowToContact() does, ensuring that
            // when the contact submits the flow, handleFlowResponse() can match the
            // incoming flow_token to the correct flow and contact.
            if (!empty($flowStubs)) {
                Log::info('MetaFlows: processing FLOW stubs after template send', [
                    'stubs'          => $flowStubs,
                    'organization_id' => $contact->organization_id,
                    'contact_id'     => $contact->id,
                ]);
            }

            foreach ($flowStubs as $stub) {
                if (empty($stub['flow_token']) || empty($stub['meta_flow_id'])) {
                    Log::warning('MetaFlows: skipping stub — missing flow_token or meta_flow_id', [
                        'stub' => $stub,
                    ]);
                    continue;
                }
                $metaFlow = \Modules\MetaFlows\Models\MetaFlow::where('meta_flow_id', $stub['meta_flow_id'])
                    ->where('organization_id', $contact->organization_id)
                    ->first();
                if ($metaFlow) {
                    \Modules\MetaFlows\Models\MetaFlowResponse::create([
                        'organization_id' => $contact->organization_id,
                        'meta_flow_id'    => $metaFlow->id,
                        'contact_id'      => $contact->id,
                        'flow_token'      => $stub['flow_token'],
                        'response_json'   => null,
                    ]);
                    Log::info('MetaFlows: MetaFlowResponse stub created for template FLOW button', [
                        'meta_flow_response_created' => true,
                        'meta_flow_id'               => $metaFlow->id,
                        'meta_flow_name'             => $metaFlow->name ?? null,
                        'flow_token'                 => $stub['flow_token'],
                        'contact_id'                 => $contact->id,
                        'organization_id'            => $contact->organization_id,
                    ]);
                } else {
                    Log::warning('MetaFlows: no MetaFlow found for stub — stub NOT created', [
                        'meta_flow_id_searched' => $stub['meta_flow_id'],
                        'organization_id'       => $contact->organization_id,
                    ]);
                }
            }
        }

        if (($responseObject->success ?? false) === true) {
            WebhookHelper::triggerWebhookEvent('message.sent', [
                'data' => $responseObject,
            ], $contact->organization_id);
        }

        return $responseObject;
    }

    private function extractTemplateVariables(array $templateContent): array
    {
        $variables = [
            'header' => [],
            'body' => [],
            'buttons' => [],
        ];

        foreach ($templateContent['components'] ?? [] as $component) {
            $type = strtolower($component['type'] ?? '');

            if ($type === 'header') {
                foreach (($component['parameters'] ?? []) as $param) {
                    $paramType = $param['type'] ?? null;
                    if ($paramType === 'text') {
                        $variables['header'][] = $param['text'] ?? null;
                    } elseif (isset($param[$paramType]['link'])) {
                        $variables['header'][] = $param[$paramType]['link'];
                    } elseif (isset($param[$paramType]['id'])) {
                        $variables['header'][] = $param[$paramType]['id'];
                    }
                }
            }

            if ($type === 'body') {
                foreach (($component['parameters'] ?? []) as $param) {
                    $variables['body'][] = $param['text'] ?? null;
                }
            }

            if ($type === 'button') {
                $buttonEntry = [
                    'sub_type' => $component['sub_type'] ?? null,
                    'index' => $component['index'] ?? null,
                    'parameters' => [],
                ];

                foreach (($component['parameters'] ?? []) as $param) {
                    if (isset($param['text'])) {
                        $buttonEntry['parameters'][] = $param['text'];
                    } elseif (isset($param['payload'])) {
                        $buttonEntry['parameters'][] = $param['payload'];
                    } elseif (isset($param['coupon_code'])) {
                        $buttonEntry['parameters'][] = $param['coupon_code'];
                    }
                }

                $variables['buttons'][] = $buttonEntry;
            }
        }

        return $variables;
    }

    public function enrichTemplateHeaderWithMediaId(array $templateContent, ?Template $templateModel = null, array $context = []): array
    {
        $templateMedia = $this->resolveTemplateHeaderMedia($templateModel);

        foreach ($templateContent['components'] ?? [] as $componentIndex => $component) {
            if (strtolower($component['type'] ?? '') !== 'header') {
                continue;
            }

            foreach (($component['parameters'] ?? []) as $parameterIndex => $parameter) {
                $mediaType = strtolower((string) ($parameter['type'] ?? ''));

                if (!in_array($mediaType, ['image', 'video', 'document'], true)) {
                    continue;
                }

                if (!empty($parameter[$mediaType]['id'] ?? null)) {
                    Log::info('Template header media already uses Meta media ID', array_merge($context, [
                        'media_type' => $mediaType,
                        'media_id' => $parameter[$mediaType]['id'],
                    ]));

                    return $templateContent;
                }

                $mediaUrl = $parameter[$mediaType]['link']
                    ?? $templateMedia['url']
                    ?? null;

                if (!$mediaUrl || !filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                    throw new \RuntimeException(__('Missing media for header.'));
                }

                $mediaId = $this->getOrUploadTemplateHeaderMediaId(
                    $mediaUrl,
                    $mediaType,
                    $templateModel,
                    $context
                );

                if (!$mediaId) {
                    if ($this->canSendTemplateHeaderMediaByLink($mediaUrl, $mediaType, $context)) {
                        return $templateContent;
                    }

                    throw new \RuntimeException(__('Unable to upload template header media before sending.'));
                }

                unset($templateContent['components'][$componentIndex]['parameters'][$parameterIndex][$mediaType]['link']);
                $templateContent['components'][$componentIndex]['parameters'][$parameterIndex][$mediaType]['id'] = $mediaId;

                Log::info('Template header media link replaced with Meta media ID', array_merge($context, [
                    'media_type' => $mediaType,
                    'media_id' => $mediaId,
                    'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
                ]));

                return $templateContent;
            }
        }

        return $templateContent;
    }

    private function canSendTemplateHeaderMediaByLink(string $mediaUrl, string $mediaType, array $context = []): bool
    {
        $access = $this->inspectMediaUrlAccessibility($mediaUrl);
        $httpStatus = $access['http_status'] ?? null;

        $canFallback = is_int($httpStatus) && $httpStatus >= 200 && $httpStatus < 400;

        if ($canFallback) {
            Log::warning('Template header media upload failed; falling back to media link', array_merge($context, [
                'media_type' => $mediaType,
                'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
                'http_status' => $httpStatus,
                'source' => $access['source'] ?? null,
            ]));
        } else {
            Log::error('Template header media upload failed and link is not accessible', array_merge($context, [
                'media_type' => $mediaType,
                'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
                'http_status' => $httpStatus,
                'source' => $access['source'] ?? null,
                'access_error' => $access['error'] ?? null,
            ]));
        }

        return $canFallback;
    }

    private function getOrUploadTemplateHeaderMediaId(string $mediaUrl, string $mediaType, ?Template $templateModel = null, array $context = []): ?string
    {
        $templateKey = $templateModel?->id
            ? 'template_' . $templateModel->id
            : 'payload_' . sha1(($templateModel?->name ?? '') . '|' . ($templateModel?->language ?? ''));

        $cacheKey = 'template_header_media_id:' . sha1(implode('|', [
            (string) $this->phoneNumberId,
            $templateKey,
            $mediaType,
            $mediaUrl,
        ]));

        $cachedMediaId = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if ($cachedMediaId) {
            Log::info('Template header media ID loaded from cache', array_merge($context, [
                'media_type' => $mediaType,
                'media_id' => $cachedMediaId,
                'media_cache_key' => $cacheKey,
                'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
            ]));

            return (string) $cachedMediaId;
        }

        $access = $this->inspectMediaUrlAccessibility($mediaUrl);
        Log::info('Template header media URL checked before upload', array_merge($context, [
            'media_type' => $mediaType,
            'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
            'http_status' => $access['http_status'] ?? null,
            'source' => $access['source'] ?? null,
        ]));

        $mediaId = $this->uploadMediaForSending($mediaUrl, array_merge($context, [
            'media_type' => $mediaType,
            'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
        ]), 'template_header');

        if ($mediaId) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $mediaId, 86400);
            Log::info('Template header media ID cached', array_merge($context, [
                'media_type' => $mediaType,
                'media_id' => $mediaId,
                'media_cache_key' => $cacheKey,
                'media_url' => $this->sanitizeMediaUrlForLog($mediaUrl),
            ]));
        }

        return $mediaId;
    }

    private function resolveTemplateHeaderMedia(?Template $templateModel): ?array
    {
        if (!$templateModel) {
            return null;
        }

        $metadata = json_decode($templateModel->metadata ?? '{}', true);

        if (!is_array($metadata)) {
            return null;
        }

        $storedMedia = $metadata['_default_header_media'] ?? $metadata['default_header_media'] ?? null;
        $mediaId = is_array($storedMedia) ? ($storedMedia['media_id'] ?? $storedMedia['chat_media_id'] ?? null) : null;
        $url = is_array($storedMedia) ? ($storedMedia['url'] ?? $storedMedia['path'] ?? null) : null;

        if (!$url && $mediaId) {
            $chatMedia = ChatMedia::find($mediaId);
            $url = $chatMedia?->path;
        }

        if (!$url) {
            foreach (($metadata['components'] ?? []) as $component) {
                if (strtoupper((string) ($component['type'] ?? '')) === 'HEADER') {
                    $headerHandle = $component['example']['header_handle'][0] ?? null;
                    if (is_string($headerHandle) && filter_var($headerHandle, FILTER_VALIDATE_URL)) {
                        $url = $headerHandle;
                    }
                    break;
                }
            }
        }

        $header = null;
        foreach (($metadata['components'] ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'HEADER') {
                $header = $component;
                break;
            }
        }

        $format = strtolower((string) ($header['format'] ?? ''));

        return $url ? [
            'type' => in_array($format, ['image', 'video', 'document'], true) ? $format : null,
            'url' => $url,
            'name' => is_array($storedMedia) ? ($storedMedia['name'] ?? $storedMedia['filename'] ?? null) : null,
            'media_id' => $mediaId,
        ] : null;
    }

    private function inspectMediaUrlAccessibility(string $mediaUrl): array
    {
        try {
            $relativePath = $this->localMediaPathFromUrl($mediaUrl);

            if ($relativePath) {
                return [
                    'source' => 'local',
                    'http_status' => \Storage::disk('local')->exists($relativePath) ? 200 : 404,
                ];
            }

            $response = \Http::timeout(10)->head($mediaUrl);

            return [
                'source' => 'remote',
                'http_status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'source' => 'remote',
                'http_status' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function sanitizeMediaUrlForLog(?string $mediaUrl): ?string
    {
        if (!$mediaUrl) {
            return null;
        }

        $parts = parse_url($mediaUrl);

        if (!$parts || empty($parts['host'])) {
            return $mediaUrl;
        }

        $path = $parts['path'] ?? '';
        $scheme = $parts['scheme'] ?? 'https';

        return $scheme . '://' . $parts['host'] . $path;
    }

    private function templatePayloadHasHeaderMedia(array $templateContent): bool
    {
        foreach ($templateContent['components'] ?? [] as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'header') {
                continue;
            }

            foreach (($component['parameters'] ?? []) as $parameter) {
                if (in_array(strtolower((string) ($parameter['type'] ?? '')), ['image', 'video', 'document'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Enrich carousel template payload with fresh media IDs.
     * Uses campaign-level caching so media is uploaded only ONCE per campaign,
     * then reused for all contacts. Meta media IDs are valid for 30 days.
     *
     * @param array $templatePayload The carousel template payload
     * @param int|null $campaignId Optional campaign ID for cross-contact caching
     * @return array The enriched payload with media IDs
     */
    public function enrichCarouselWithMediaIds(array $templatePayload, ?int $campaignId = null): array
    {
        $cacheKey = $campaignId
            ? "carousel_media_ids_campaign_{$campaignId}"
            : $this->carouselMediaIdsCacheKey($templatePayload);
        $cachedMediaIds = [];

        // Try to load cached media IDs for this campaign or direct-send payload.
        if ($cacheKey) {
            $cachedMediaIds = \Illuminate\Support\Facades\Cache::get($cacheKey, []);
            if (!empty($cachedMediaIds)) {
                Log::info('Carousel media IDs loaded from cache', [
                    'campaign_id' => $campaignId,
                    'cached_count' => count($cachedMediaIds),
                ]);
            }
        }

        $newMediaIds = [];
        $uploadCount = 0;
        $cacheHitCount = 0;

        // Find carousel component
        foreach ($templatePayload['components'] as &$component) {
            if (($component['type'] ?? '') === 'carousel') {
                // Process each card
                foreach ($component['cards'] as $cardIndex => &$card) {
                    // Find header component in card
                    foreach ($card['components'] as &$cardComponent) {
                        if (($cardComponent['type'] ?? '') === 'header') {
                            $headerParam = &$cardComponent['parameters'][0] ?? null;
                            
                            if ($headerParam) {
                                $mediaType = $headerParam['type'] ?? 'image';
                                $mediaUrl = $headerParam[$mediaType]['link'] ?? null;
                                
                                if ($mediaUrl) {
                                    // Check cache first (keyed by card index)
                                    $mediaId = $cachedMediaIds[$cardIndex] ?? null;

                                    if ($mediaId) {
                                        $cacheHitCount++;
                                    } else {
                                        // Upload and get fresh media ID
                                        $uploadStart = microtime(true);
                                        $mediaId = $this->uploadMediaForSending($mediaUrl);
                                        $uploadDuration = round((microtime(true) - $uploadStart) * 1000, 2);

                                        Log::info('Carousel card media uploaded', [
                                            'card_index' => $cardIndex,
                                            'media_id' => $mediaId,
                                            'duration_ms' => $uploadDuration,
                                            'campaign_id' => $campaignId,
                                        ]);
                                        $uploadCount++;
                                    }
                                    
                                    if ($mediaId) {
                                        // Store for caching
                                        $newMediaIds[$cardIndex] = $mediaId;
                                        // Replace 'link' with 'id'
                                        unset($headerParam[$mediaType]['link']);
                                        $headerParam[$mediaType]['id'] = $mediaId;
                                    } else {
                                        Log::error('Failed to get media ID for carousel card', [
                                            'card_index' => $cardIndex,
                                            'url' => $mediaUrl,
                                            'campaign_id' => $campaignId,
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // Cache the media IDs for this campaign (valid for 24 hours — Meta IDs last 30 days)
        if ($cacheKey && !empty($newMediaIds)) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, $newMediaIds, 86400);
            Log::info('Carousel media IDs cached for campaign', [
                'campaign_id' => $campaignId,
                'uploads' => $uploadCount,
                'cache_hits' => $cacheHitCount,
                'total_cards' => count($newMediaIds),
            ]);
        }

        return $templatePayload;
    }

    private function carouselMediaIdsCacheKey(array $templatePayload): ?string
    {
        $mediaUrls = [];

        foreach ($templatePayload['components'] ?? [] as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'carousel') {
                continue;
            }

            foreach (($component['cards'] ?? []) as $cardIndex => $card) {
                foreach (($card['components'] ?? []) as $cardComponent) {
                    if (strtolower((string) ($cardComponent['type'] ?? '')) !== 'header') {
                        continue;
                    }

                    foreach (($cardComponent['parameters'] ?? []) as $parameter) {
                        $mediaType = strtolower((string) ($parameter['type'] ?? ''));

                        if (in_array($mediaType, ['image', 'video', 'document'], true)) {
                            $mediaUrl = $parameter[$mediaType]['link'] ?? null;

                            if ($mediaUrl) {
                                $mediaUrls[$cardIndex] = $mediaUrl;
                            }
                        }
                    }
                }
            }
        }

        if (empty($mediaUrls)) {
            return null;
        }

        return 'carousel_media_ids_payload:' . sha1(json_encode([
            'phone_number_id' => $this->phoneNumberId,
            'template' => $templatePayload['name'] ?? null,
            'language' => $templatePayload['language']['code'] ?? null,
            'media_urls' => $mediaUrls,
        ]));
    }

    /**
     * Check if a template payload is a carousel by scanning its components.
     */
    private function isCarouselPayload(array $templateContent): bool
    {
        foreach ($templateContent['components'] ?? [] as $component) {
            if (strtolower($component['type'] ?? '') === 'carousel') {
                return true;
            }
        }
        return false;
    }

    public function carouselPayloadHasMediaLinks(array $templateContent): bool
    {
        foreach ($templateContent['components'] ?? [] as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'carousel') {
                continue;
            }

            foreach (($component['cards'] ?? []) as $card) {
                foreach (($card['components'] ?? []) as $cardComponent) {
                    if (strtolower((string) ($cardComponent['type'] ?? '')) !== 'header') {
                        continue;
                    }

                    foreach (($cardComponent['parameters'] ?? []) as $parameter) {
                        $mediaType = strtolower((string) ($parameter['type'] ?? ''));

                        if (
                            in_array($mediaType, ['image', 'video', 'document'], true)
                            && !empty($parameter[$mediaType]['link'] ?? null)
                            && empty($parameter[$mediaType]['id'] ?? null)
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve the Template model ID from the payload's name + language.
     * This lets us look up the full carousel_cards metadata for chat storage.
     */
    private function resolveTemplateIdFromPayload(array $templateContent): int
    {
        $template = Template::where('organization_id', $this->organizationId)
            ->where('name', $templateContent['name'])
            ->where('language', $templateContent['language']['code'])
            ->first();

        return $template->id;
    }

    private function resolveTemplateFromPayload(array $templateContent): ?Template
    {
        $name = $templateContent['name'] ?? null;
        $language = $templateContent['language']['code'] ?? null;

        if (!$name || !$language) {
            return null;
        }

        return Template::where('organization_id', $this->organizationId)
            ->where('name', $name)
            ->where('language', $language)
            ->first();
    }

    /**
     * Upload media file and return its media ID for sending carousel templates
     */
    private function uploadMediaForSending(string $fileUrl, array $context = [], string $filenamePrefix = 'carousel'): ?string
    {
        try {
            // Get file info
            $fileData = $this->getFileDataForUpload($fileUrl);
            
            if (!$fileData) {
                Log::error('Could not get file data', array_merge($context, [
                    'url' => $this->sanitizeMediaUrlForLog($fileUrl),
                ]));
                return null;
            }

            $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/media";
            
            // Create a temporary file with proper extension
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            fwrite($tempFile, $fileData['content']);
            
            // Determine proper filename with extension
            $extension = $this->getExtensionFromMimeType($fileData['mime_type']);
            $filename = $filenamePrefix . '_' . uniqid() . '.' . $extension;
            
            $response = \Http::timeout(120)
                ->connectTimeout(20)
                ->retry(2, 1000)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->accessToken,
                ])->attach(
                    'file',
                    $fileData['content'],
                    $filename
                )->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type' => $fileData['mime_type'],  // Explicitly set type
                ]);

            // Clean up temp file
            fclose($tempFile);

            if ($response->successful()) {
                $mediaId = $response->json('id');
                Log::info('Media uploaded for WhatsApp send', array_merge($context, [
                    'media_id' => $mediaId,
                    'mime_type' => $fileData['mime_type'],
                    'filename' => $filename
                ]));
                return $mediaId;
            }

            Log::error('Media upload failed', array_merge($context, [
                'response' => $response->json(),
                'mime_type' => $fileData['mime_type'],
                'url' => $this->sanitizeMediaUrlForLog($fileUrl)
            ]));
            return null;
            
        } catch (\Exception $e) {
            Log::error('uploadMediaForSending error', array_merge($context, [
                'error' => $e->getMessage(),
                'url' => $this->sanitizeMediaUrlForLog($fileUrl)
            ]));
            return null;
        }
    }

    /**
     * Get file content and MIME type from URL
     */
    private function getFileDataForUpload(string $url): ?array
    {
        try {
            $decodedPath = $this->localMediaPathFromUrl($url);

            if ($decodedPath) {
                if (\Storage::disk('local')->exists($decodedPath)) {
                    $content = \Storage::disk('local')->get($decodedPath);
                    $mimeType = \Storage::disk('local')->mimeType($decodedPath);

                    return [
                        'content' => $content,
                        'mime_type' => $mimeType,
                    ];
                }

                Log::warning('Local file not found', ['path' => $decodedPath]);
                return null;
            }
            
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0',
                    'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,video/*,application/pdf,*/*;q=0.8',
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::error('Could not fetch external URL', [
                    'url' => $this->sanitizeMediaUrlForLog($url),
                    'status' => $response->status(),
                    'content_type' => $response->header('Content-Type'),
                ]);
                return null;
            }

            $content = $response->body();

            // Detect MIME type from content or URL
            $mimeType = $response->header('Content-Type') ?: $this->detectMimeType($content, $url);
            $mimeType = trim(explode(';', $mimeType)[0]);

            return [
                'content' => $content,
                'mime_type' => $mimeType,
            ];
            
        } catch (\Exception $e) {
            Log::error('getFileDataForUpload error', [
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return null;
        }
    }

    private function localMediaPathFromUrl(string $url): ?string
    {
        $appUrl = rtrim(config('app.url'), '/');
        $mediaPrefix = $appUrl . '/media/';

        if (str_starts_with($url, $mediaPrefix)) {
            return urldecode(substr($url, strlen($mediaPrefix)));
        }

        $urlParts = parse_url($url);
        $path = $urlParts['path'] ?? null;

        if (!$path) {
            return null;
        }

        $host = $urlParts['host'] ?? null;
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if ($host && $appHost && strcasecmp($host, $appHost) !== 0) {
            return null;
        }

        $mediaPathPosition = strpos($path, '/media/');

        if ($mediaPathPosition === false) {
            return null;
        }

        return urldecode(substr($path, $mediaPathPosition + strlen('/media/')));
    }

    /**
     * Detect MIME type from content or URL
     */
    private function detectMimeType(string $content, string $url): string
    {
        // Try to detect from content using finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);
        
        // If detection worked and it's not generic, use it
        if ($mimeType && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }
        
        // Fallback: detect from URL extension
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        $extensionMap = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'mp4'  => 'video/mp4',
            '3gpp' => 'video/3gpp',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'aac'  => 'audio/aac',
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'opus' => 'audio/opus',
            'amr'  => 'audio/amr',
        ];
        
        return $extensionMap[$extension] ?? 'application/octet-stream';
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMimeType(string $mimeType): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'video/mp4'  => 'mp4',
            'video/3gpp' => '3gp',
            'application/pdf' => 'pdf',
            'audio/mpeg' => 'mp3',
            'audio/ogg'  => 'ogg',
            'audio/aac'  => 'aac',
        ];
        
        return $mimeMap[$mimeType] ?? 'bin';
    }

    /**
     * Get file content from local storage or external URL
     */
    private function getFileContentFromUrl(string $url): ?string
    {
        try {
            $appUrl = rtrim(config('app.url'), '/');
            
            // If it's a local URL, read from storage
            if (str_starts_with($url, $appUrl . '/media/')) {
                $relativePath = str_replace($appUrl . '/media/', '', $url);
                $decodedPath = urldecode($relativePath);
                
                if (\Storage::disk('local')->exists($decodedPath)) {
                    return \Storage::disk('local')->get($decodedPath);
                }
                
                Log::warning('Local file not found', ['path' => $decodedPath]);
            }
            
            // Otherwise fetch from external URL
            $content = @file_get_contents($url);
            
            if ($content === false) {
                Log::error('Could not fetch external URL', ['url' => $url]);
                return null;
            }
            
            return $content;
            
        } catch (\Exception $e) {
            Log::error('getFileContentFromUrl error', ['error' => $e->getMessage(), 'url' => $url]);
            return null;
        }
    }

    public function getMediaIdFromCampaign($campaignId){
        $campaign = Campaign::where('id', $campaignId)->first();
        $templateMetadata = json_decode($campaign->metadata);
        $mediaId = null;

        if(isset($templateMetadata->media)){
            $mediaId = $templateMetadata->media;
        }

        return $mediaId;
    }

    public function buildCampaignTemplateChatMessage($templateMetadata, $contactUuId, ?Contact $contact = null, ?array $displayHeaderMedia = null, ?Template $templateModel = null){
        $contact = $contact ?? Contact::where('uuid', $contactUuId)->first();
        $array = [];
        
        // Convert to object if it's an array (from carousel implementation)
        if (is_array($templateMetadata)) {
            $templateMetadata = json_decode(json_encode($templateMetadata));
        }
        
        // Default type
        $array['type'] = 'text';
        
        // HEADER - check if exists first
        if (isset($templateMetadata->header)) {
            if (isset($templateMetadata->header->format) && 
                ($templateMetadata->header->format == 'IMAGE' || 
                $templateMetadata->header->format == 'VIDEO' || 
                $templateMetadata->header->format == 'DOCUMENT' || 
                $templateMetadata->header->format == 'LOCATION')) {
                $array['type'] = strtolower($templateMetadata->header->format);
            }
            
            if (isset($templateMetadata->header->text)) {
                $headerText = $templateMetadata->header->text;
                
                if (isset($templateMetadata->header->parameters) && !empty($templateMetadata->header->parameters)) {
                    $headerParameters = $templateMetadata->header->parameters;
                    
                    if ($headerParameters && count($headerParameters) >= 1) {
                        foreach ($headerParameters as $index => $parameter) {
                            $placeholder = '{{' . ($index + 1) . '}}';
                            $value = $parameter->selection === 'static' ? $parameter->value : $this->getParameters($contact, $parameter->value);
                            $headerText = str_replace($placeholder, $value, $headerText);
                        }
                    }
                }
                
                $array['header']['text'] = $headerText;
            }
        }
        
        // BODY
        if (isset($templateMetadata->body->text)) {
            $bodyText = $templateMetadata->body->text;
            
            if (isset($templateMetadata->body->parameters) && !empty($templateMetadata->body->parameters)) {
                $bodyParameters = $templateMetadata->body->parameters;
                
                if ($bodyParameters && count($bodyParameters) >= 1) {
                    foreach ($bodyParameters as $index => $parameter) {
                        $placeholder = '{{' . ($index + 1) . '}}';
                        $value = $parameter->selection === 'static' ? $parameter->value : $this->getParameters($contact, $parameter->value);
                        $bodyText = str_replace($placeholder, $value, $bodyText);
                    }
                }
            }
            
            if ($array['type'] == 'text') {
                $array[$array['type']]['body'] = $bodyText;
            } else {
                $array[$array['type']]['caption'] = $bodyText;
            }
        }
        
        // FOOTER
        if (isset($templateMetadata->footer->text)) {
            $array[$array['type']]['footer'] = $templateMetadata->footer->text;
        }
        
        // BUTTONS
        if (isset($templateMetadata->buttons)) {
            foreach ($templateMetadata->buttons as $key => $button) {
                $array['buttons'][$key]['type'] = $button->type;
                $array['buttons'][$key]['text'] = $button->text;
                $array['buttons'][$key]['value'] = $button->value;
                
                if (isset($button->parameters)) {
                    $array['buttons'][$key]['parameters'] = $button->parameters;
                }
            }
        }

        $displayHeaderMedia = $displayHeaderMedia
            ?? $this->extractCampaignHeaderMediaForDisplay($templateMetadata)
            ?? $this->resolveTemplateHeaderMedia($templateModel);

        if (
            $displayHeaderMedia
            && isset($array['type'])
            && in_array($array['type'], ['image', 'video', 'document'], true)
            && !empty($displayHeaderMedia['url'])
        ) {
            $array[$array['type']]['url'] = $displayHeaderMedia['url'];
            $array[$array['type']]['link'] = $displayHeaderMedia['url'];
            if (!empty($displayHeaderMedia['name'])) {
                $array[$array['type']]['filename'] = $displayHeaderMedia['name'];
            }
        }
        
        return json_encode($this->withCompleteTemplateChatMetadata($array));
    }

    private function extractCampaignHeaderMediaForDisplay(object $templateMetadata): ?array
    {
        if (!isset($templateMetadata->header->parameters) || empty($templateMetadata->header->parameters)) {
            return null;
        }

        foreach ($templateMetadata->header->parameters as $parameter) {
            $mediaType = strtolower((string) ($parameter->type ?? ''));

            if (!in_array($mediaType, ['image', 'video', 'document'], true)) {
                continue;
            }

            $mediaPayload = $parameter->{$mediaType} ?? null;
            $mediaUrl = $mediaPayload->link
                ?? $mediaPayload->url
                ?? $parameter->url
                ?? $parameter->value
                ?? null;

            if (is_string($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                return [
                    'type' => $mediaType,
                    'url' => $mediaUrl,
                    'name' => $mediaPayload->filename ?? $mediaPayload->name ?? null,
                ];
            }
        }

        return null;
    }

    private function buildTemplateChatMessage($templateContent, $contact, ?array $displayHeaderMedia = null){
        //Get the template
        $template = Template::where('organization_id', $contact->organization_id)
            ->where('name', $templateContent['name'])
            ->where('language', $templateContent['language']['code'])
            ->first();



        $template = json_decode($template->metadata);
        $templateMetadatas = $template->components;
        $array = [];
        $array['type'] = 'text';

        foreach($templateMetadatas as $templateMetadata){
            if($templateMetadata->type == 'HEADER'){
                if($templateMetadata->format == 'IMAGE' || $templateMetadata->format == 'VIDEO' || $templateMetadata->format == 'DOCUMENT' || $templateMetadata->format == 'LOCATION'){
                    $array['type'] = strtolower($templateMetadata->format);
                } else if($templateMetadata->format == 'TEXT' && isset($templateMetadata->text)){
                    $headerText = $templateMetadata->text;

                    // Check for parameters in templateContent components
                    if (isset($templateContent['components']) && !empty($templateContent['components'])) {
                        foreach($templateContent['components'] as $component) {
                            if ($component['type'] === 'header' && isset($component['parameters'])) {
                                $headerParameters = $component['parameters'];

                                if($headerParameters && count($headerParameters) >= 1){
                                    foreach($headerParameters as $index => $parameter){
                                        $placeholder = '{{' . ($index + 1) . '}}';
                                        $value = $parameter['type'] === 'text' ? $parameter['text'] : $this->getParameters($contact, $parameter['text']);

                                        $headerText = str_replace($placeholder, $value, $headerText);
                                    }
                                }

                                break; // Found header parameters, no need to continue
                            }
                        }
                    }

                    $array['header']['text'] = $headerText;
                }
            }

            //BODY
            if($templateMetadata->type == 'BODY'){
                if(isset($templateMetadata->text)){
                    $bodyText = $templateMetadata->text;



                    // Check for parameters in templateContent components
                    if (isset($templateContent['components']) && !empty($templateContent['components'])) {
                        foreach($templateContent['components'] as $component) {
                            if ($component['type'] === 'body' && isset($component['parameters'])) {
                                $bodyParameters = $component['parameters'];
                                


                                if($bodyParameters && count($bodyParameters) >= 1){
                                    foreach($bodyParameters as $index => $parameter){
                                        $placeholder = '{{' . ($index + 1) . '}}';
                                        $value = $parameter['type'] === 'text' ? $parameter['text'] : $this->getParameters($contact, $parameter['text']);



                                        $bodyText = str_replace($placeholder, $value, $bodyText);
                                    }
                                }


                                break; // Found body parameters, no need to continue
                            }
                        }
                    }

                    if($array['type'] == 'text'){
                        $array[$array['type']]['body'] = $bodyText;
                    } else {
                        $array[$array['type']]['caption'] = $bodyText;
                    }
                }
            }

            //FOOTER
            if($templateMetadata->type == 'FOOTER'){
                $array[$array['type']]['footer'] = $templateMetadata->text;
            }

            //BUTTONS
            if($templateMetadata->type == 'BUTTONS'){
                foreach($templateMetadata->buttons as $key => $button){
                    $array['buttons'][$key]['type'] = $button->type;
                    $array['buttons'][$key]['text'] = $button->text;
                    $array['buttons'][$key]['value'] = $button->text;
    
                    if(isset($button->parameters)){
                        $array['buttons'][$key]['parameters'] = $button->parameters;
                    }
                }
            }
        }

        if (
            $displayHeaderMedia
            && isset($array['type'])
            && in_array($array['type'], ['image', 'video', 'document'], true)
            && !empty($displayHeaderMedia['url'])
        ) {
            $array[$array['type']]['url'] = $displayHeaderMedia['url'];
            $array[$array['type']]['link'] = $displayHeaderMedia['url'];
            if (!empty($displayHeaderMedia['name'])) {
                $array[$array['type']]['filename'] = $displayHeaderMedia['name'];
            }
        }

        //Log::info(json_encode($array));
        return json_encode($this->withCompleteTemplateChatMetadata($array));
    }

    private function withCompleteTemplateChatMetadata(array $metadata): array
    {
        $type = strtolower((string) ($metadata['type'] ?? 'text'));
        $typedPayload = is_array($metadata[$type] ?? null) ? $metadata[$type] : [];

        $bodyText = $typedPayload['body'] ?? $typedPayload['caption'] ?? null;
        if ($bodyText !== null && $bodyText !== '') {
            $metadata['body'] = [
                'text' => $bodyText,
            ];
        }

        $footerText = $typedPayload['footer'] ?? null;
        if ($footerText !== null && $footerText !== '') {
            $metadata['footer'] = [
                'text' => $footerText,
            ];
        }

        if (isset($metadata['buttons']) && is_array($metadata['buttons'])) {
            $metadata['buttons'] = array_values($metadata['buttons']);
        }

        if (in_array($type, ['image', 'video', 'document'], true)) {
            $media = [
                'type' => $type,
                'url' => $typedPayload['url'] ?? $typedPayload['link'] ?? $typedPayload['path'] ?? null,
                'id' => $typedPayload['id'] ?? null,
                'filename' => $typedPayload['filename'] ?? $typedPayload['name'] ?? null,
                'name' => $typedPayload['name'] ?? $typedPayload['filename'] ?? null,
                'mime_type' => $typedPayload['mime_type'] ?? null,
                'size' => $typedPayload['size'] ?? null,
            ];

            $media = array_filter($media, static fn ($value) => $value !== null && $value !== '');

            if (!empty($media)) {
                $metadata['media'] = $media;
                unset($metadata[$type]);
                $metadata['header']['format'] = strtoupper($type);
            }
        }

        return $metadata;
    }

    private function extractTemplateHeaderMediaForDisplay(array $templateContent, ?Template $templateModel): ?array
    {
        foreach (($templateContent['components'] ?? []) as $component) {
            if (strtolower((string) ($component['type'] ?? '')) !== 'header') {
                continue;
            }

            foreach (($component['parameters'] ?? []) as $parameter) {
                $mediaType = strtolower((string) ($parameter['type'] ?? ''));

                if (!in_array($mediaType, ['image', 'video', 'document'], true)) {
                    continue;
                }

                $mediaUrl = $parameter[$mediaType]['link'] ?? null;

                if (is_string($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
                    return [
                        'type' => $mediaType,
                        'url' => $mediaUrl,
                        'name' => $parameter[$mediaType]['filename'] ?? null,
                    ];
                }
            }
        }

        $templateMedia = $this->resolveTemplateHeaderMedia($templateModel);

        if (!$templateMedia || empty($templateMedia['url'])) {
            return null;
        }

        return [
            'type' => strtolower((string) ($templateMedia['type'] ?? '')),
            'url' => $templateMedia['url'],
            'name' => $templateMedia['name'] ?? null,
        ];
    }

    private function getParameters($contact, $parameter){
        if($parameter === 'first name'){
            return $contact->first_name;
        } else if($parameter === 'last name'){
            return $contact->last_name;
        } else if($parameter === 'name'){
            return $contact->first_name . ' ' . $contact->last_name;
        } else if($parameter === 'email'){
            return $contact->email;
        } else if($parameter === 'phone'){
            return $contact->phone;
        }
    }

    /**
     * This function sends media content via a POST request and uploads the media to Facebook's resumable API.
     * Note that media types can only be audio, document, image, sticker, or video.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $mediaType The type of media being uploaded. Valid options are audio, document, image, sticker, or video.
     * @param string $mediaFile The file to be uploaded as media.
     * @return mixed Returns the response from the HTTP request.
     */
    /*public function sendMedia($contactUuid, $mediaType, $mediaFile)
    {
        $contact = Contact::where('uuid', $contactUuId)->first();
        $mediaFilePath = Storage::path("media/{$mediaFileName}");

        $fileUploadResponse = $this->initiateResumableUploadSession($mediaFilePath);

        if(!$fileUploadResponse->success){
            return $fileUploadResponse;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = $mediaType;
        $requestData[$mediaType]['id'] = $fileUploadResponse->data->h;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }*/

    /**
     * This function sends a stored image as a media file via a POST request to the specified phone number using Facebook's messaging API.
     *
     * @param string $contactUuId The UUID of the contact to whom the image will be sent.
     * @param string $imageUrl The URL of the stored image.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendMedia($contactUuId, $mediaType, $mediaFileName, $mediaFilePath, $mediaUrl, $location, $caption = NULL, $transcription = NULL, $userId = null)
    {
        $contact = Contact::where('uuid', $contactUuId)->first();

        if (! $contact) {
            return $this->buildErrorResponse('Contact not found');
        }

        if ((bool) ($contact->is_blocked ?? false)) {
            return $this->buildBlockedContactResponse($contact);
        }

        $subscriptionGuard = $this->guardExpiredSubscription((int) $contact->organization_id);
        if ($subscriptionGuard !== null) {
            return $subscriptionGuard;
        }

        $conversationGuard = $this->guardConversationLimit((int) $contact->organization_id);
        if ($conversationGuard !== null) {
            return $conversationGuard;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $contact->phone;
        $requestData['type'] = $mediaType;
        $requestData[$mediaType]['link'] = $mediaUrl;

        if($mediaType == 'document'){
            $requestData[$mediaType]['filename'] = $mediaFileName;
        }

        if($caption != NULL && $mediaType != 'audio'){
            $requestData[$mediaType]['caption'] = $caption;
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        //Log::info(json_encode($responseObject));

        if($responseObject->success === true){
            //Log::info($mediaUrl);
            $wamId = $responseObject->data->messages[0]->id;
            $contentType = $this->getContentTypeFromUrl($mediaUrl);
            $mediaData = $this->formatMediaResponse($wamId, $mediaUrl, $mediaType, $contentType, $transcription, $caption);
            $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

            $media = ChatMedia::create([
                'name' => $mediaFileName,
                'path' => $mediaUrl,
                'location' => $location,
                'type' => $contentType,
                'size' => $mediaSize,
            ]);

            $chat = Chat::create([
                'organization_id' => $contact->organization_id,
                'wam_id' => $wamId,
                'contact_id' => $contact->id,
                'type' => 'outbound',
                'user_id' => $userId,
                'metadata' => json_encode($mediaData),
                'media_id' => $media->id,
                'status' => 'sent',
                'created_at' => now()
            ]);

            $this->recordConversationWindowForOutbound($chat, $contact);

            $chatlogId = ChatLog::insertGetId([
                'contact_id' => $contact->id,
                'entity_type' => 'chat',
                'entity_id' => $chat->id,
                'created_at' => now()
            ]);

            $chat = Chat::with('contact','media')->where('id', $chat->id)->first();
            $responseObject->data->chat = $chat;

            $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
            $chatArray = array([
                'type' => 'chat',
                'value' => $chatLogArray->relatedEntities
            ]);

            event(new NewChatEvent($chatArray, $contact->organization_id));
        }

        //Log::info(json_encode($responseObject, true));

        if (($responseObject->success ?? false) === true) {
            WebhookHelper::triggerWebhookEvent('message.sent', [
                'data' => $responseObject,
            ], $contact->organization_id);
        }

        return $responseObject;
    }

    function getContentTypeFromUrl($url) {
        try {
            $appUrl = rtrim(config('app.url'), '/');
            $mediaPrefix = $appUrl . '/media/';

            if (str_starts_with($url, $mediaPrefix)) {
                $relative = substr($url, strlen($mediaPrefix));
                $segments = explode('/', $relative);
                $decoded = array_map('rawurldecode', $segments);
                $relativePath = implode('/', $decoded);

                $localPath = storage_path('app/' . ltrim($relativePath, '/'));
                if (file_exists($localPath)) {
                    $type = \File::mimeType($localPath);
                    if ($type === 'application/octet-stream') {
                        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
                        $map = [
                            'aac' => 'audio/aac',
                            'amr' => 'audio/amr',
                            'mp3' => 'audio/mpeg',
                            'm4a' => 'audio/mp4',
                            'mp4' => 'audio/mp4',
                            'ogg' => 'audio/ogg; codecs=opus',
                            'wav' => 'audio/wav',
                        ];

                        if (isset($map[$ext])) {
                            return $map[$ext];
                        }
                    }

                    return $type;
                }
            }

            // Fallback to HTTP HEAD for external URLs
            $response = Http::head($url);

            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    private function buildBlockedContactResponse(Contact $contact): \stdClass
    {
        Log::info('Outbound message prevented for blocked contact', [
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'contact_uuid' => $contact->uuid,
        ]);

        return $this->buildErrorResponse('Contact is blocked', 403, 'CONTACT_BLOCKED');
    }

    private function guardExpiredSubscription(int $organizationId): ?\stdClass
    {
        $message = SubscriptionService::outboundSubscriptionExpirationMessage($organizationId);
        if ($message === null) {
            return null;
        }

        return $this->buildErrorResponse(
            $message,
            403,
            'SUBSCRIPTION_EXPIRED'
        );
    }

    private function guardConversationLimit(int $organizationId): ?\stdClass
    {
        $check = (new SubscriptionUsageService())->canUse($organizationId, SubscriptionUsageService::CONVERSATIONS);

        if ($check['allowed']) {
            return null;
        }

        return $this->buildErrorResponse($check['message'], 403, 'CONVERSATIONS_LIMIT_REACHED');
    }

    private function guardTemplateMessagesLimit(int $organizationId): ?\stdClass
    {
        $check = (new SubscriptionUsageService())->canUse($organizationId, SubscriptionUsageService::TEMPLATE_MESSAGES);

        if ($check['allowed']) {
            return null;
        }

        return $this->buildErrorResponse($check['message'], 403, 'TEMPLATE_MESSAGES_LIMIT_REACHED');
    }

    private function recordConversationWindowForOutbound(Chat $chat, Contact $contact): void
    {
        (new SubscriptionUsageService())->ensureConversationWindowForInbound(
            (int) $chat->organization_id,
            (int) $contact->id,
            (int) $chat->id
        );
    }

    private function attachTemplateBillingMetadata($metadata, ?int $campaignId, ?string $billingSource = null, ?int $billingSourceId = null): string
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $decoded = is_array($decoded) ? $decoded : [];

        $resolvedSource = $billingSource ?: ($campaignId ? 'campaign' : 'live_chat');
        $resolvedSourceId = $billingSourceId ?? $campaignId;

        $decoded['message_type'] = 'template';
        $decoded['billing'] = array_merge($decoded['billing'] ?? [], [
            'is_template' => true,
            'resource' => SubscriptionUsageService::TEMPLATE_MESSAGES,
            'source' => $resolvedSource,
            'source_id' => $resolvedSourceId,
            'count_on_status' => 'delivered',
        ]);

        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function buildErrorResponse(string $message, int $statusCode = 422, ?string $code = null): \stdClass
    {
        $response = new \stdClass();
        $response->success = false;
        $response->message = $message;
        $response->status_code = $statusCode;
        $response->data = new \stdClass();
        $response->data->error = [
            'code' => $code,
            'message' => $message,
        ];

        return $response;
    }

    function formatMediaResponse($wamId, $mediaUrl, $mediaType, $contentType, $transcription = null, $caption = null){
        $response = [
            "id" => $wamId,
            "type" => $mediaType,
            $mediaType => [
                "mime_type" => $contentType,
            ]
        ];

        if ($mediaType === 'audio' && $transcription) {
            $response['transcript'] = $transcription;
        }

        if ($caption !== null && $caption !== '' && $mediaType !== 'audio') {
            $response[$mediaType]['caption'] = $caption;
        }

        return $response;
    }

    function getMediaSizeInBytesFromUrl($url) {
         Log::info('Checking media URL start', [
        'url' => $url,
    ]);
        try {
            $appUrl = rtrim(config('app.url'), '/');
            $mediaPrefix = $appUrl . '/media/';

            // If the URL points to our app's /media/* route, read the file directly from storage
            if (str_starts_with($url, $mediaPrefix)) {
                $relative = substr($url, strlen($mediaPrefix));
                // decode each segment
                $segments = explode('/', $relative);
                $decoded = array_map('rawurldecode', $segments);
                $relativePath = implode('/', $decoded);

                $localPath = storage_path('app/' . ltrim($relativePath, '/'));

                if (file_exists($localPath)) {
                    $size = filesize($localPath);
                    Log::info('Media loaded from local storage', ['url' => $url, 'localPath' => $localPath, 'size' => $size]);
                    return $size;
                }

                Log::warning('Media not found in local storage', ['url' => $url, 'localPath' => $localPath]);
                return null;
            }

            // Fallback: attempt to fetch via HTTP
            $imageContent = @file_get_contents($url);

            if ($imageContent !== false) {
                $data = strlen($imageContent);
                Log::info('Media loaded successfully', [
                    'url'  => $url,
                    'strlen' => $data
                ]);

                return $data;
            }
        } catch (\Exception $e) {
            Log::error('getMediaSizeInBytesFromUrl:error', ['message' => $e->getMessage(), 'url' => $url]);
        }

        return null;
    }

    /**
     * This function allows you to react to a specific message with an emoji via a POST request to Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param string $wamId The ID of the message you want to react to.
     * @param string $emoji The emoji you want to use as a reaction.
     * @return mixed Returns the response from the HTTP request.
     */
    public function reactToMessage($phoneNumber, $wamId, $emoji)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['recipient_type'] = 'individual';
        $requestData['to'] = $phoneNumber;
        $requestData['type'] = 'reaction';
        $requestData['reaction']['message_id'] = $wamId;
        $requestData['reaction']['emoji'] = $emoji;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }

    /**
     * This function sends a location to a specific phone number via a POST request using Facebook's messaging API.
     *
     * @param string $phoneNumber The phone number of the recipient.
     * @param object $location The location object containing longitude, latitude, name, and address.
     * @return mixed Returns the response from the HTTP request.
     */
    public function sendLocation($phoneNumber, $location)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['to'] = $phoneNumber;
        $requestData['type'] = 'location';
        $requestData['location']['longitude'] = $location->longitude;
        $requestData['location']['latitude'] = $location->latitude;
        $requestData['location']['name'] = $location->name;
        $requestData['location']['address'] = $location->address;

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        dd($responseObject);
    }

    public function createTemplate(Request $request)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";
        $defaultHeaderMedia = null;
        
        $requestData = [
            "name" => $request->name,
            "language" => $request->language,
            "category" => $request->category,
        ];

        if($request->customize_ttl && $request->message_send_ttl_seconds){
            $requestData['message_send_ttl_seconds'] = $request->message_send_ttl_seconds;
        }

        if($request->category != 'AUTHENTICATION'){
            if($request->header['format'] === 'TEXT'){
                if(isset($request->header['text'])){
                    $headerComponent = [];

                    $headerComponent['type'] = "HEADER";
                    $headerComponent['format'] = $request->header['format'];
                    $headerComponent['text'] = $request->header['text'];

                    if (!empty($request->header['example'])) {
                        $headerComponent['example']['header_text'] = $request->header['example'];
                    }

                    $requestData['components'][] = $headerComponent;
                }
            }
        

            if(($request->header['format'] === 'IMAGE' || $request->header['format'] === 'VIDEO' || $request->header['format'] === 'DOCUMENT')){
                if(isset($request->header['example'])){
                    $fileUploadResponse = $this->initiateResumableUploadSession($request->header['example']);

                    if(!$fileUploadResponse->success){
                        return $fileUploadResponse;
                    }

                    $defaultHeaderMedia = $this->storeTemplateHeaderMedia(
                        $request->header['example'],
                        $request->header['format']
                    );

                    $requestData['components'][] = [
                        "type" => "HEADER",
                        "format" => $request->header['format'],
                        "example" => [
                            "header_handle" => [
                                $fileUploadResponse->data->h
                            ]
                        ]
                    ];
                }
            }
        }
        
        if($request->category == 'AUTHENTICATION'){
            $bodyComponent = [];
            $bodyComponent['type'] = "BODY";
            $bodyComponent['add_security_recommendation'] = $request->body['add_security_recommendation'];

            $requestData['components'][] = $bodyComponent;
        } else {
            $bodyComponent = [];

            if($request->body['text'] != null){
                $bodyComponent['type'] = "BODY";
                $bodyComponent['text'] = $request->body['text'];

                if (!empty($request->body['example'])) {
                    $bodyComponent['example']['body_text'][] = $request->body['example'];
                }

                $requestData['components'][] = $bodyComponent;
            }
        }

        if ($request->has('footer')) {
            if($request->category != 'AUTHENTICATION'){
                if(isset($request->footer['text']) &&  $request->footer['text'] != null){
                    $requestData['components'][] = [
                        "type" => "FOOTER",
                        "text" => $request->footer['text']
                    ];
                }
            } else {
                $requestData['components'][] = [
                    "type" => "FOOTER",
                    "code_expiration_minutes" => $request->footer['code_expiration_minutes']
                ];
            }
        }

        if($request->category != 'AUTHENTICATION'){
            if ($request->has('buttons')) {
                if (!isset($requestData['components'])) {
                    $requestData['components'] = [];
                }

                $buttonsComponent = [
                    'type' => 'BUTTONS',
                    'buttons' => []
                ];

                foreach ($request->buttons as $button) {
                    $buttonType = strtoupper($button['type'] ?? '');

                    if ($buttonType === 'QUICK_REPLY') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                        ];
                    } elseif ($buttonType === 'URL') {
                        $urlButton = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                            'url' => $button['url'],
                        ];

                        if (str_contains($button['url'] ?? '', '{{') && !empty($button['example'])) {
                            $urlButton['example'] = is_array($button['example'])
                                ? array_values($button['example'])
                                : [$button['example']];
                        }

                        $buttonsComponent['buttons'][] = $urlButton;
                    } elseif ($buttonType === 'PHONE_NUMBER') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                            'phone_number' => ($button['country'] ?? '') . ($button['phone_number'] ?? ''),
                        ];
                    } elseif ($buttonType === 'COPY_CODE') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'example' => $button['example'] ?? '',
                        ];
                    } elseif ($buttonType === 'FLOW') {
                        $buttonsComponent['buttons'][] = [
                            'type'            => 'FLOW',
                            'text'            => $button['text'],
                            'flow_id'         => $button['flow_id'],
                            'navigate_screen' => $button['navigate_screen'],
                        ];
                    }
                }

                if (!empty($buttonsComponent['buttons'])) {
                    $requestData['components'][] = $buttonsComponent;
                }
            }
        } else {
            $authenticationButton = $request->input('authentication_button', []);
            // The UI uses lowercase values for its internal state, while the
            // WhatsApp Cloud API requires the OTP type enum in uppercase.
            $otpType = strtoupper((string) $authenticationButton['otp_type']);
            $button = [
                'type' => $authenticationButton['type'],
                'otp_type' => $otpType,
            ];

            if($otpType !== 'COPY_CODE'){
                $button['text'] = $authenticationButton['text'];
                $button['autofill_text'] = $authenticationButton['autofill_text'];
                $button['supported_apps'] = $authenticationButton['supported_apps'];
            }

            // Meta now only accepts its approved, localized Copy Code label.
            // Omitting the optional text field makes Meta select that label for
            // the template language and avoids error 2388060.

            if ($otpType === 'ZERO_TAP') {
                $button['zero_tap_terms_accepted'] = $authenticationButton['zero_tap_terms_accepted'];
            }

            $requestData['components'][] = [
                'type' => 'BUTTONS',
                'buttons' => [$button],
            ];
        }

        $client = new Client();
        $responseObject = new \stdClass();

        //Log::info($requestData);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());

            //Save Template To Database
            $template = new Template();
            $template->organization_id = session()->get('current_organization');
            $template->meta_id = $responseObject->data->id;
            $template->name = $request->name;
            $template->category = $request->category;
            $template->language = $request->language;
            $metadataToSave = $requestData;
            if ($defaultHeaderMedia) {
                $metadataToSave['_default_header_media'] = $defaultHeaderMedia;
            }
            $template->metadata = json_encode($metadataToSave);
            $template->status = $responseObject->data->status;
            $template->created_by = auth()->user()->id;
            $template->created_at = now();
            $template->updated_at = now();
            $template->save();
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function createCarouselTemplate(Request $request)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";

        $cards     = $request->input('cards', []);
        $cardFiles = $request->file('card_files', []);

        $uploader = new MediaUploadService(
            $this->accessToken,
            $this->apiVersion,
            $this->appId
        );

        $carouselCards = [];   // → components[1].cards  (sent to Meta)
        $savedCards    = [];   // → metadata.carousel_cards  (persisted for Edit)
        $uploadConcurrency = (int) config('services.meta.carousel_upload_concurrency', 3);
        $uploadResults = $uploader->uploadManyToMeta($cardFiles, $uploadConcurrency);

        foreach ($cards as $index => $card) {
            $cardComponents = [];   // components array INSIDE this card

            // ── HEADER ──────────────────────────────────────────────────────────
            if (isset($cardFiles[$index])) {
                $uploadResult = $uploadResults[$index] ?? null;

                if (!$uploadResult || !$uploadResult->success) {
                    $responseObject = new \stdClass();
                    $responseObject->success = false;
                    $responseObject->data    = new \stdClass();
                    $responseObject->data->error = new \stdClass();
                    $responseObject->data->error->message = $uploadResult->error ?? 'Failed to upload card media';
                    return $responseObject;
                }

                // Save locally
                $localInfo = $uploader->saveLocally($cardFiles[$index], "carousel_card_{$index}_" . time());


                // Matches docs: { "type": "header", "format": "image", "example": { "header_handle": [...] } }
                $cardComponents[] = [
                    'type'   => 'header',
                    'format' => strtolower($card['header_type'] ?? 'image'),   // docs use lowercase
                    'example' => [
                        'header_handle' => [$uploadResult->handle],
                    ],
                ];

                $savedCards[$index] = [
                    'header_type'   => $card['header_type'] ?? 'IMAGE',
                    'header_handle' => $uploadResult->handle,
                    'local_path'    => $localInfo['path'] ?? null,  
                    'local_url'     => $localInfo['url'] ?? null,
                ];
            }

            // ── BODY (optional per card, but if ANY card has it, ALL must) ──────
            // Docs: card body is a component inside card.components with type "body"
            // Note: this is CARD body, separate from the top-level message body
            if (!empty($card['body_text'])) {
                $cardComponents[] = [
                    'type' => 'body',
                    'text' => $card['body_text'],
                ];
                $savedCards[$index]['body_text'] = $card['body_text'];
            }

            // ── BUTTONS ─────────────────────────────────────────────────────────
            // Docs: { "type": "buttons", "buttons": [ ... ] }
            if (!empty($card['buttons'])) {
                $buttons = [];
                foreach ($card['buttons'] as $btn) {
                    $button = [
                        'type' => strtolower($btn['type']),   // docs use lowercase: quick_reply, url, phone_number
                        'text' => $btn['text'] ?? '',
                    ];

                    if (strtolower($btn['type']) === 'url' && isset($btn['url'])) {
                        $button['url'] = $btn['url'];
                    } elseif (strtolower($btn['type']) === 'phone_number') {
                        $button['phone_number'] = ($btn['country'] ?? '') . ($btn['phone_number'] ?? '');
                    } elseif (strtolower($btn['type']) === 'copy_code' && isset($btn['example'])) {
                        $button['example'] = $btn['example'];
                    }

                    $buttons[] = $button;
                }

                $cardComponents[] = [
                    'type'    => 'buttons',
                    'buttons' => $buttons,
                ];
                $savedCards[$index]['buttons'] = $card['buttons'];
            }

            // Each card is an object with a "components" key wrapping the array
            // Docs: cards: [ { "components": [ ... ] }, { "components": [ ... ] } ]
            $carouselCards[] = [
                'components' => $cardComponents,
            ];
        }

        // ── Full payload ────────────────────────────────────────────────────────
        // Docs require components in this order:
        //   [0] → { "type": "body", "text": "..." }          ← REQUIRED by Meta
        //   [1] → { "type": "carousel", "cards": [...] }
        $requestData = [
            'name'     => $request->name,
            'language' => $request->language,
            'category' => strtolower($request->category),
            'components' => [
                [
                    'type' => 'body',
                    'text' => $request->carousel_message_body,
                ],
                [
                    'type'  => 'carousel',
                    'cards' => $carouselCards,
                ],
            ],
        ];

        // \Log::debug(json_encode($requestData, JSON_PRETTY_PRINT));

        // ── POST to Meta ────────────────────────────────────────────────────────
        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data    = json_decode($response->getBody()->getContents());

            // ── Persist to DB ───────────────────────────────────────────────────
            $metaToStore = $requestData;
            $metaToStore['template_type']  = 'carousel';
            $metaToStore['carousel_cards'] = array_values($savedCards);

            $template = new Template();
            $template->organization_id = session()->get('current_organization');
            $template->meta_id         = $responseObject->data->id;
            $template->name            = $request->name;
            $template->category        = $request->category;
            $template->language        = $request->language;
            $template->metadata        = json_encode($metaToStore);
            $template->status          = $responseObject->data->status;
            $template->created_by      = auth()->user()->id;
            $template->created_at      = now();
            $template->updated_at      = now();
            $template->save();

        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data    = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data    = json_decode($response->getBody()->getContents());
            $responseObject->message = $responseObject->data->error->error_user_msg
                ?? $responseObject->data->error->message
                ?? $e->getMessage();
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data    = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function updateTemplate(Request $request, $uuid)
    {
        $template = Template::where('uuid', $uuid)->first();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$template->meta_id}";
        $existingMetadata = json_decode($template->metadata ?? '{}', true);
        $defaultHeaderMedia = $existingMetadata['_default_header_media'] ?? null;
        
        $requestData = [
            //"name" => $request->name,
            //"language" => $request->language,
            "category" => $template->status == 'APPROVED' ? $template->category : $request->category,
        ];

        if($request->customize_ttl && $request->message_send_ttl_seconds){
            $requestData['message_send_ttl_seconds'] = $request->message_send_ttl_seconds;
        }

        if($request->category != 'AUTHENTICATION'){
            if($request->header['format'] === 'TEXT'){
                if(isset($request->header['text'])){
                    $headerComponent = [];

                    $headerComponent['type'] = "HEADER";
                    $headerComponent['format'] = $request->header['format'];
                    $headerComponent['text'] = $request->header['text'];

                    if (!empty($request->header['example'])) {
                        $headerComponent['example']['header_text'] = $request->header['example'];
                    }

                    $requestData['components'][] = $headerComponent;
                }
            }

            if(($request->header['format'] === 'IMAGE' || $request->header['format'] === 'VIDEO' || $request->header['format'] === 'DOCUMENT')){
                if(isset($request->header['example'])){
                    $fileUploadResponse = $this->initiateResumableUploadSession($request->header['example']);

                    if(!$fileUploadResponse->success){
                        return $fileUploadResponse;
                    }

                    $defaultHeaderMedia = $this->storeTemplateHeaderMedia(
                        $request->header['example'],
                        $request->header['format']
                    );

                    $requestData['components'][] = [
                        "type" => "HEADER",
                        "format" => $request->header['format'],
                        "example" => [
                            "header_handle" => [
                                $fileUploadResponse->data->h
                            ]
                        ]
                    ];
                } else {
                    // Decode existing metadata
                    $metadata = $existingMetadata;

                    // Extract existing header if available
                    $existingHeader = [];
                    if (isset($metadata['components'])) {
                        foreach ($metadata['components'] as $component) {
                            if ($component['type'] === 'HEADER') {
                                $existingHeader = $component;
                                break;
                            }
                        }
                    }

                    $requestData['components'][] = $existingHeader;
                }
            }
        }

        if($request->category == 'AUTHENTICATION'){
            $bodyComponent = [];
            $bodyComponent['type'] = "BODY";
            $bodyComponent['add_security_recommendation'] = $request->body['add_security_recommendation'];

            $requestData['components'][] = $bodyComponent;
        } else {
            if($request->body['text'] != null){
                $bodyComponent = [];

                $bodyComponent['type'] = "BODY";
                $bodyComponent['text'] = $request->body['text'];

                if (!empty($request->body['example'])) {
                    $bodyComponent['example']['body_text'][] = $request->body['example'];
                }

                $requestData['components'][] = $bodyComponent;
            }
        }

        if ($request->has('footer')) {
            if($request->category != 'AUTHENTICATION'){
                if($request->footer['text'] != null){
                    $requestData['components'][] = [
                        "type" => "FOOTER",
                        "text" => $request->footer['text']
                    ];
                }
            } else {
                $requestData['components'][] = [
                    "type" => "FOOTER",
                    "code_expiration_minutes" => $request->footer['code_expiration_minutes']
                ];
            }
        }

        if($request->category != 'AUTHENTICATION'){
            if ($request->has('buttons')) {
                if (!isset($requestData['components'])) {
                    $requestData['components'] = [];
                }

                $buttonsComponent = [
                    'type' => 'BUTTONS',
                    'buttons' => []
                ];

                foreach ($request->buttons as $button) {
                    $buttonType = strtoupper($button['type'] ?? '');

                    if ($buttonType === 'QUICK_REPLY') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                        ];
                    } elseif ($buttonType === 'URL') {
                        $urlButton = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                            'url' => $button['url'],
                        ];

                        if (str_contains($button['url'] ?? '', '{{') && !empty($button['example'])) {
                            $urlButton['example'] = is_array($button['example'])
                                ? array_values($button['example'])
                                : [$button['example']];
                        }

                        $buttonsComponent['buttons'][] = $urlButton;
                    } elseif ($buttonType === 'PHONE_NUMBER') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'text' => $button['text'],
                            'phone_number' => ($button['country'] ?? '') . ($button['phone_number'] ?? ''),
                        ];
                    } elseif ($buttonType === 'COPY_CODE') {
                        $buttonsComponent['buttons'][] = [
                            'type' => $buttonType,
                            'example' => $button['example'] ?? '',
                        ];
                    } elseif ($buttonType === 'FLOW') {
                        $buttonsComponent['buttons'][] = [
                            'type'            => 'FLOW',
                            'text'            => $button['text'],
                            'flow_id'         => $button['flow_id'],
                            'navigate_screen' => $button['navigate_screen'],
                        ];
                    }
                }

                if (!empty($buttonsComponent['buttons'])) {
                    $requestData['components'][] = $buttonsComponent;
                }
            }
        } else {
            $authenticationButton = $request->input('authentication_button', []);
            // The UI uses lowercase values for its internal state, while the
            // WhatsApp Cloud API requires the OTP type enum in uppercase.
            $otpType = strtoupper((string) $authenticationButton['otp_type']);
            $button = [
                'type' => $authenticationButton['type'],
                'otp_type' => $otpType,
            ];

            if($otpType !== 'COPY_CODE'){
                $button['text'] = $authenticationButton['text'];
                $button['autofill_text'] = $authenticationButton['autofill_text'];
                $button['supported_apps'] = $authenticationButton['supported_apps'];
            }

            // Meta now only accepts its approved, localized Copy Code label.
            // Omitting the optional text field makes Meta select that label for
            // the template language and avoids error 2388060.

            if ($otpType === 'ZERO_TAP') {
                $button['zero_tap_terms_accepted'] = $authenticationButton['zero_tap_terms_accepted'];
            }

            $requestData['components'][] = [
                'type' => 'BUTTONS',
                'buttons' => [$button],
            ];
        }

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());

            //Update Template In Database
            if ($template) {
                $template->organization_id = session()->get('current_organization');
                $template->category = $template->status == 'APPROVED' ? $template->category : $request->category;
                $metadataToSave = $requestData;
                if ($defaultHeaderMedia) {
                    $metadataToSave['_default_header_media'] = $defaultHeaderMedia;
                }
                $template->metadata = json_encode($metadataToSave);
                $template->status = 'PENDING';
                $template->created_by = auth()->user()->id;
                $template->updated_at = now(); // No need to set `created_at` when updating
                $template->save();
            } else {
                // Handle case where template is not found (optional)
                throw new \Exception('Template not found');
            }
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    /**
     * Update an existing carousel template.
     *
     * Only text is editable on an approved carousel:
     *   - carousel_message_body  (top-level body)
     *   - body_text per card
     *   - button text per card (type/url/phone_number are locked)
     *
     * Media cannot change — we re-use the header_handle values stored in
     * metadata.carousel_cards (which after sync are CDN URLs, but Meta still
     * accepts them in header_handle on update).
     *
     * Payload shape mirrors createCarouselTemplate exactly:
     *   components[0] → { type: "body", text: "..." }
     *   components[1] → { type: "carousel", cards: [ { components: [...] }, ... ] }
     */
    public function updateCarouselTemplate(Request $request, $uuid)
    {
        $responseObject = new \stdClass();
        $template       = Template::where('uuid', $uuid)->first();

        if (!$template) {
            $responseObject->success = false;
            $responseObject->data    = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = 'Template not found.';
            return $responseObject;
        }

        $existingMeta  = json_decode($template->metadata, true);
        $originalCards = $existingMeta['carousel_cards'] ?? [];

        // What the frontend sent (text edits only)
        $updatedCards        = $request->carousel_cards ?? [];
        $updatedMessageBody  = $request->carousel_message_body ?? '';

        Log::info('REQUEST -> all', $request->all());
        Log::info('existingMeta', [$existingMeta]);

        // ─── RE-UPLOAD MEDIA FROM LOCAL STORAGE ────────────────────────────────
        $uploader = new MediaUploadService(
            $this->accessToken,
            $this->apiVersion,
            $this->appId
        );

        $freshHandles = [];
        foreach ($originalCards as $index => $originalCard) {
            $localPath = $originalCard['local_path'] ?? null;
            
            if (!$localPath || !\Storage::disk('local')->exists($localPath)) {
                Log::error('Carousel card media missing', [
                    'card_index' => $index,
                    'local_path' => $localPath,
                ]);
                
                $responseObject->success = false;
                $responseObject->data    = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->message = "Media file missing for card " . ($index + 1);
                return $responseObject;
            }

            // Read file from local storage
            $fileContent = \Storage::disk('local')->get($localPath);
            $mimeType    = \Storage::disk('local')->mimeType($localPath);
            $fileName    = basename($localPath);

            // Create a temporary UploadedFile instance
            $tempPath = sys_get_temp_dir() . '/' . $fileName;
            file_put_contents($tempPath, $fileContent);
            
            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $tempPath,
                $fileName,
                $mimeType,
                null,
                true // test mode - don't validate
            );

            // Re-upload to Meta → get fresh handle
            $uploadResult = $uploader->uploadToMeta($uploadedFile);
            
            // Clean up temp file
            @unlink($tempPath);

            if (!$uploadResult->success) {
                Log::error('Failed to re-upload carousel card media', [
                    'card_index' => $index,
                    'error'      => $uploadResult->error,
                ]);
                
                $responseObject->success = false;
                $responseObject->data    = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->message = $uploadResult->error ?? 'Media upload failed';
                return $responseObject;
            }

            $freshHandles[$index] = $uploadResult->handle;
            
            Log::info('Fresh handle generated', [
                'card_index' => $index,
                'handle' => $uploadResult->handle,
            ]);
        }

        // ─── BUILD PAYLOAD WITH FRESH HANDLES ──────────────────────────────────
        $metaCards = [];

        foreach ($originalCards as $index => $originalCard) {
            $newCard = $updatedCards[$index] ?? $originalCard;
            $cardComponents = [];

            // HEADER – use FRESH upload handle
            $cardComponents[] = [
                'type'   => 'header',
                'format' => strtolower($originalCard['header_type'] ?? 'image'),
                'example' => [
                    'header_handle' => [$freshHandles[$index]], // ← FRESH HANDLE as array
                ],
            ];

            // BODY – use updated text from request
            $bodyText = $newCard['body_text'] ?? $originalCard['body_text'] ?? null;
            if ($bodyText !== null && $bodyText !== '') {
                $cardComponents[] = [
                    'type' => 'body',
                    'text' => $bodyText,
                ];
            }

            // BUTTONS – text is editable, type/url/phone are locked
            $buttons = $newCard['buttons'] ?? $originalCard['buttons'] ?? [];
            if (!empty($buttons)) {
                $buttonsPayload = [];
                
                foreach ($buttons as $btn) {
                    $btnType = strtoupper($btn['type'] ?? '');
                    
                    $btnItem = [
                        'type' => strtolower($btn['type']), // lowercase for Meta
                        'text' => $btn['text'] ?? '',
                    ];

                    if ($btnType === 'URL') {
                        $btnItem['url'] = $btn['url'] ?? '';
                    } elseif ($btnType === 'PHONE_NUMBER') {
                        $btnItem['phone_number'] = ($btn['country'] ?? '') . ($btn['phone_number'] ?? '');
                    }

                    $buttonsPayload[] = $btnItem;
                }

                $cardComponents[] = [
                    'type'    => 'buttons',
                    'buttons' => $buttonsPayload, // ← Must be array of button objects
                ];
            }

            $metaCards[] = [
                'components' => $cardComponents,
            ];
        }

        // ─── FINAL PAYLOAD ─────────────────────────────────────────────────────
        $requestData = [
            'category'   => 'marketing',
            'components' => [
                [
                    'type' => 'body',
                    'text' => $updatedMessageBody,
                ],
                [
                    'type'  => 'carousel',
                    'cards' => $metaCards,
                ],
            ],
        ];

        // Better logging - use print_r to avoid "over 9 levels" issue
        Log::debug('Carousel Update Payload', [
            'payload_json' => json_encode($requestData, JSON_PRETTY_PRINT)
        ]);

        // ─── POST TO META ──────────────────────────────────────────────────────
        $url    = "https://graph.facebook.com/{$this->apiVersion}/{$template->meta_id}";
        $client = new Client();

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $requestData,
            ]);

            $responseObject->success = true;
            $responseObject->data    = json_decode($response->getBody()->getContents());

            // ─── UPDATE LOCAL METADATA ─────────────────────────────────────────
            $updatedCarouselCards = [];
            foreach ($originalCards as $index => $originalCard) {
                $newCard = $updatedCards[$index] ?? $originalCard;
                $updatedCarouselCards[] = [
                    'header_type'   => $originalCard['header_type'],
                    'header_handle' => $originalCard['header_handle'], // Keep CDN URL for reference
                    'local_path'    => $originalCard['local_path'],    // Preserve local file
                    'local_url'     => $originalCard['local_url'] ?? null, // Preserve local URL
                    'body_text'     => $newCard['body_text'] ?? $originalCard['body_text'],
                    'buttons'       => $newCard['buttons']   ?? $originalCard['buttons'],
                ];
            }

            $existingMeta['carousel_cards']        = $updatedCarouselCards;
            $existingMeta['carousel_message_body'] = $updatedMessageBody;
            $existingMeta['components']            = $requestData['components'];

            $template->metadata   = json_encode($existingMeta);
            $template->status     = 'PENDING';
            $template->updated_at = now();
            $template->save();

            Log::info('Template updated successfully', ['template_id' => $template->id]);

        } catch (ConnectException $e) {
            Log::error('Connection error updating carousel', ['error' => $e->getMessage()]);
            $responseObject->success = false;
            $responseObject->data    = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
           $response = $e->getResponse();
            $errorBody = $response->getBody()->getContents();
            Log::error('Meta API error updating carousel', ['error' => $errorBody]);
            
            $responseObject->success = false;
            $responseObject->data    = json_decode($errorBody);
            
            // Prioritize user-friendly message
            $errorData = $responseObject->data->error ?? null;
            
            if ($errorData) {
                $responseObject->message = $errorData->error_user_msg 
                    ?? $errorData->error_user_title 
                    ?? $errorData->message 
                    ?? 'Something went wrong';
            } else {
                $responseObject->message = 'Something went wrong';
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error updating carousel', ['error' => $e->getMessage()]);
            $responseObject->success = false;
            $responseObject->data    = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function syncTemplates()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";

        $client = self::getSharedClient();
        $responseObject = new \stdClass();
        $organizationId = (int) $this->organizationId;
        $syncOrganization = Organization::find($organizationId);
        if (! $syncOrganization || (string) data_get(json_decode($syncOrganization->metadata ?: '{}', true), 'whatsapp.waba_id') !== (string) $this->wabaId) {
            return (object) ['success' => false, 'message' => 'WhatsApp account does not match the organization.'];
        }

        try {
            do {
                $response = $client->request('GET', $url, [
                    'headers' => [
                        'Authorization' => "OAuth {$this->accessToken}",
                    ],
                    'connect_timeout' => 10,
                    'timeout' => 30,
                ]);

                $responseObject = json_decode($response->getBody()->getContents());

                if (! $responseObject instanceof \stdClass || ! isset($responseObject->data) || ! is_array($responseObject->data)) {
                    throw new \RuntimeException('Unexpected response while synchronizing WhatsApp templates.');
                }

                foreach($responseObject->data as $templateData){
                    DB::transaction(function () use ($organizationId, $templateData) {
                        $organization = Organization::query()->lockForUpdate()->find($organizationId);
                        $connection = json_decode($organization?->metadata ?: '{}', true);
                        if (! $organization || (string) data_get($connection, 'whatsapp.waba_id') !== (string) $this->wabaId
                            || (string) data_get($connection, 'whatsapp.access_token') !== (string) $this->accessToken) {
                            throw new \RuntimeException('WhatsApp connection changed during template synchronization.');
                        }
                        $template = Template::where('organization_id', $organizationId)
                            ->where('meta_id', $templateData->id)->first();

                        if($template){
                            $template = Template::where('organization_id', $organizationId)
                                ->lockForUpdate()->findOrFail($template->id);
                            $oldStatus = (string) $template->status;
                            // ── Carousel: preserve our carousel_cards before overwriting ──
                            // Meta's response replaces header_handle with CDN URLs and drops
                            // any custom keys we stored. We rebuild carousel_cards from the
                            // synced metadata so the Edit page still has what it needs.
                            $existingMeta = $template->metadata ? json_decode($template->metadata, true) : [];
                            $newMeta      = json_decode(json_encode($templateData), true); // stdClass → array

                            if ($this->isCarouselTemplate($newMeta)) {
                                $newMeta = $this->rebuildCarouselMeta($newMeta, $existingMeta);
                            }

                            if (isset($existingMeta['_default_header_media'])) {
                                $newMeta['_default_header_media'] = $existingMeta['_default_header_media'];
                            }

                            $template->metadata = json_encode($newMeta);
                            $template->name = $templateData->name ?? $template->name;
                            $template->language = $templateData->language ?? $template->language;
                            $template->status = $templateData->status;
                            if ($oldStatus !== (string) $template->status) {
                                $template->notification_transition_sequence++;
                                $template->last_status_change_at = now();
                            }
                            $template->category = $templateData->category ?? $template->category;
                            $template->updated_at = now();
                            $template->deleted_at = NULL;
                            $template->save();
                            if ($oldStatus !== (string) $template->status) {
                                app(TemplateStatusNotificationRecorder::class)->record(
                                    $template, $organization, $oldStatus, (string) $template->status
                                );
                            }
                        } else {
                            // New template from Meta we don't have locally yet
                            $newMeta = json_decode(json_encode($templateData), true);

                            // If it's a carousel that somehow we never saved (e.g. created
                            // outside this app), build carousel_cards from scratch
                            if ($this->isCarouselTemplate($newMeta)) {
                                $newMeta = $this->rebuildCarouselMeta($newMeta, []);
                            }

                            $template = new Template();
                            $template->organization_id = $organizationId;
                            $template->meta_id = $templateData->id;
                            $template->name = $templateData->name;
                            $template->category = $templateData->category;
                            $template->language = $templateData->language;
                            $template->metadata = json_encode($newMeta);
                            $template->status = $templateData->status;
                            $template->created_by = auth()->id()
                                ?? $organization->created_by;
                            $template->created_at = now();
                            $template->updated_at = now();
                            $template->save();
                        }
                    });
                };

                if(isset($responseObject->paging) && isset($responseObject->paging->next)) {
                    $url = $responseObject->paging->next;
                } else {
                    $url = null;
                }
            } while($url);
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = $response
                ? json_decode($response->getBody()->getContents())
                : null;
            $responseObject->message = data_get($responseObject, 'data.error.error_user_msg')
                ?? data_get($responseObject, 'data.error.message')
                ?? $e->getMessage();
        } catch (\Throwable $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // CAROUSEL HELPERS — only called from syncTemplates, kept private
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Check whether a metadata array contains a CAROUSEL component.
     * Works regardless of case (Meta returns uppercase after sync).
     */
    private function isCarouselTemplate(array $meta): bool
    {
        if (!isset($meta['components']) || !is_array($meta['components'])) {
            return false;
        }

        foreach ($meta['components'] as $component) {
            if (strtoupper($component['type'] ?? '') === 'CAROUSEL') {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuild the carousel_cards array and inject template_type flag into
     * the synced metadata.
     *
     * After sync, Meta's metadata looks like this per card:
     *   {
     *     "components": [
     *       { "type": "HEADER", "format": "IMAGE", "example": { "header_handle": ["https://scontent..."] } },
     *       { "type": "BODY",   "text": "Bodyy" },
     *       { "type": "BUTTONS","buttons": [{ "type": "QUICK_REPLY", "text": "Test" }] }
     *     ]
     *   }
     *
     * We extract that into carousel_cards so the Edit page can read it:
     *   {
     *     "header_type":   "IMAGE",
     *     "header_handle": "https://scontent...",   // CDN URL now, not an upload handle
     *     "local_path":    "carousel_media/...",     // preserved from existing if available
     *     "body_text":     "Bodyy",
     *     "buttons":       [{ "type": "QUICK_REPLY", "text": "Test" }]
     *   }
     *
     * @param  array  $newMeta       The fresh metadata object from Meta's sync response
     * @param  array  $existingMeta  The metadata we had in DB before sync (may be empty)
     * @return array  The newMeta array with carousel_cards and template_type injected
     */
    private function rebuildCarouselMeta(array $newMeta, array $existingMeta): array
    {
        // Pull existing carousel_cards so we can preserve local_path values
        $existingCards = $existingMeta['carousel_cards'] ?? [];

        

        // Find the CAROUSEL component in the synced metadata
        $carouselComponent = null;
        foreach ($newMeta['components'] as $component) {
            if (strtoupper($component['type'] ?? '') === 'CAROUSEL') {
                $carouselComponent = $component;
                break;
            }
        }

        if (!$carouselComponent || !isset($carouselComponent['cards'])) {
            // Shouldn't happen, but safe fallback — just tag it and return
            $newMeta['template_type'] = 'carousel';
            return $newMeta;
        }

        $rebuiltCards = [];

        foreach ($carouselComponent['cards'] as $index => $card) {
            $cardData = [];
            $components = $card['components'] ?? [];

            foreach ($components as $comp) {
                $type = strtoupper($comp['type'] ?? '');

                switch ($type) {
                    case 'HEADER':
                        $cardData['header_type'] = strtoupper($comp['format'] ?? 'IMAGE');

                        // Meta replaces the upload handle with a CDN URL after approval.
                        // header_handle[0] is now a URL like "https://scontent.whatsapp.net/..."
                        $cardData['header_handle'] = $comp['example']['header_handle'][0] ?? null;

                        // **NEW: Preserve local paths from existing metadata**
                        if (isset($existingCards[$index])) {
                            $cardData['local_path'] = $existingCards[$index]['local_path'] ?? null;
                            $cardData['local_url']  = $existingCards[$index]['local_url'] ?? null;
                        }
                        break;

                    case 'BODY':
                        $cardData['body_text'] = $comp['text'] ?? '';
                        break;

                    case 'BUTTONS':
                        $cardData['buttons'] = $comp['buttons'] ?? [];
                        break;
                }
            }


            $rebuiltCards[] = $cardData;
        }

        // Also preserve the top-level carousel message body if it exists
        // (it's already in components as a BODY type, but we store it separately too)
        foreach ($newMeta['components'] as $component) {
            if (strtoupper($component['type'] ?? '') === 'BODY') {
                $newMeta['carousel_message_body'] = $component['text'] ?? '';
                break;
            }
        }

        $newMeta['template_type']  = 'carousel';
        $newMeta['carousel_cards'] = $rebuiltCards;

        return $newMeta;
    }

    /**
     * Build the metadata JSON for a carousel template chat record.
     *
     * This mirrors the structure that ChatBubble.vue will need to render
     * the carousel in the thread. It stores each card's header type,
     * body text, and buttons so the UI can display them without
     * re-fetching the template.
     *
     * @param  string  $contactUuid   The contact UUID (used to resolve dynamic variables in future)
     * @param  int     $templateId    The Template model ID
     * @return string                 JSON-encoded metadata for the Chat record
     */
    public function buildCarouselTemplateChatMessage(string $contactUuid, int $templateId, ?Template $template = null): string
    {
        $template = $template ?? Template::where('id', $templateId)->first();
        $metadata     = json_decode($template->metadata, true);
        $carouselCards = $metadata['carousel_cards'] ?? [];

        $array = [
            'type'                => 'carousel',
            'carousel_message_body' => $metadata['carousel_message_body'] ?? '',
            'cards'               => [],
        ];

        foreach ($carouselCards as $card) {
            $cardData = [
                'header_type'   => $card['header_type'] ?? 'IMAGE',
                'media_url'     => $card['media_url'] ?? null,
                'header_handle' => $card['header_handle'] ?? null,
                'local_url'     => $card['local_url'] ?? null,
                'body_text'     => $card['body_text'] ?? '',
                'buttons'       => $card['buttons'] ?? [],
            ];

            $array['cards'][] = $cardData;
        }

        return json_encode($array);
    }

    /**
     * This function deletes a template by its UUID via a DELETE request to Facebook's messaging API.
     *
     * @param string $uuid The UUID of the template to be deleted.
     * @return mixed Returns the response from the HTTP request.
     */
    public function deleteTemplate($uuid)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/message_templates";
        $headers = $this->setHeaders();

        $template = Template::where('uuid', $uuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (!$template) {
            $responseObject = new \stdClass();
            $responseObject->success = false;
            $responseObject->message = __('Template not found.');
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $responseObject->message;

            return $responseObject;
        }

        // Meta expects these DELETE parameters in the query string, not in a
        // JSON request body. Sending them in the body causes Meta to reject
        // the deletion request as if no template identifier was supplied.
        $url .= '?' . http_build_query([
            'hsm_id' => $template->meta_id,
            'name' => $template->name,
        ], '', '&', PHP_QUERY_RFC3986);

        $responseObject = $this->sendHttpRequest('DELETE', $url, null, $headers);

        // Deleting a template is idempotent from the application's point of
        // view. If Meta no longer knows this hsm_id, the template was already
        // deleted remotely and should be removed from the local list too.
        $errorCode = data_get($responseObject, 'data.error.code');
        $errorMessage = strtolower((string) (
            data_get($responseObject, 'data.error.message')
            ?? data_get($responseObject, 'data.error.error_user_msg')
            ?? ($responseObject->message ?? '')
        ));

        $templateAlreadyDeleted = (int) $errorCode === 100
            && str_contains($errorMessage, 'hsm_id')
            && str_contains($errorMessage, 'not a valid whatsapp business hsm id');

        if (($responseObject->success ?? false) || $templateAlreadyDeleted) {
            $template->deleted_at = now();
            $template->save();

            if ($templateAlreadyDeleted) {
                $responseObject->success = true;
                $responseObject->message = __('Template deleted successfully');
            }
        }

        return $responseObject;
    }

    function getMedia($mediaId)
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function checkHealth()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}?fields=health_status";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function subscribeToWaba()
    {
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps")->throw()->json();

            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function getWabaSubscriptions()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        return $responseObject;
    }

    function overrideCallbackUrl($callbackUrl, $verifyToken)
    {
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->post("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps", [
                'override_callback_uri' => $callbackUrl,
                'verify_token' => $verifyToken
            ])->throw()->json();

            $responseObject->success = true;
            $responseObject->data = new \stdClass();
            $responseObject->data = (object) $response;
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function unSubscribeToWaba()
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/subscribed_apps";
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('DELETE', $url, NULL, $headers);

        return $responseObject;
    }

    public function getBusinessProfile(){
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/whatsapp_business_profile", [
                'fields' => 'about,address,description,email,profile_picture_url,websites,vertical',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function updateBusinessProfile(Request $request){
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/whatsapp_business_profile";
        
        $headers = $this->setHeaders();

        $requestData['messaging_product'] = 'whatsapp';
        $requestData['about'] = $request->about;
        $requestData['address'] = $request->address;
        $requestData['description'] = $request->description;
        $requestData['vertical'] = $request->industry;
        $requestData['email'] = $request->email;
            
        $profile_picture_url = NULL;

        if($request->hasFile('profile_picture_url')){
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileContent = $request->file('profile_picture_url');

            if($storage === 'local'){
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $profile_picture_url = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } else if($storage === 'aws') {
                $file = $request->file('profile_picture_url');
                $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $profile_picture_url = $mediaFilePath;
            }

            $fileUploadResponse = $this->initiateResumableUploadSession($request->file('profile_picture_url'));

            if($fileUploadResponse->success){
                $requestData['profile_picture_handle'] = $fileUploadResponse->data->h;
            }
        }

        $responseObject = $this->sendHttpRequest('POST', $url, $requestData, $headers);

        if($responseObject->success === true){
            $organizationConfig = Organization::where('id', $this->organizationId)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['whatsapp']['business_profile']['about'] = $request->about;
            $metadataArray['whatsapp']['business_profile']['address'] = $request->address;
            $metadataArray['whatsapp']['business_profile']['description'] = $request->description;
            $metadataArray['whatsapp']['business_profile']['industry'] = $request->industry;
            $metadataArray['whatsapp']['business_profile']['email'] = $request->email;
            if($profile_picture_url != NULL){
                $metadataArray['whatsapp']['business_profile']['profile_picture_url'] = $profile_picture_url;
            }

            $updatedMetadataJson = json_encode($metadataArray);

            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();
        }

        return $responseObject;
    }

    public function deRegisterPhone(){
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/deregister";
        
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('POST', $url, NULL, $headers);

        if($responseObject->success === true){
            dd($responseObject);
        }

        dd($responseObject);
        return $responseObject;
    }

    public function getPhoneNumberId(){
        $responseObject = new \stdClass();

        try {
            $fields = 'display_phone_number,certificate,name_status,new_certificate,new_name_status,verified_name,quality_rating,messaging_limit_tier';

            $response = Http::get("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}/phone_numbers", [
                'fields' => $fields,
                'access_token' => $this->accessToken,
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response['data'][0];
        
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function getPhoneNumberStatus(){
        $responseObject = new \stdClass();

        try {

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}", [
                'fields' => 'status, code_verification_status , quality_score, health_status,whatsapp_business_manager_messaging_limit',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    public function getAccountReviewStatus(){
        $responseObject = new \stdClass();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken
            ])->get("https://graph.facebook.com/{$this->apiVersion}/{$this->wabaId}", [
                'fields' => 'account_review_status',
            ])->throw()->json();

            if (isset($response['data']['error'])) {
                $responseObject->success = false;
                $responseObject->data = new \stdClass();
                $responseObject->data->error = new \stdClass();
                $responseObject->data->error->code = $response['data']['error']['code'];
                $responseObject->data->error->message = $response['data']['error']['message'];
            } else {    
                $responseObject->success = true;
                $responseObject->data = new \stdClass();
                $responseObject->data = (object) $response;
            }
        } catch (\Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    function viewMedia($mediaId)
    {
        $response = $this->getMedia($mediaId);

        if(!$response->success){
            return $response;
        }

        $url = $response->data->url;
        $headers = $this->setHeaders();

        $responseObject = $this->sendHttpRequest('GET', $url, NULL, $headers);

        dd($responseObject);

        return $responseObject;
    }

    function initiateResumableUploadSession($file)
    {
        $sessionResponse = $this->createResumableUploadSession($file);

        if(!$sessionResponse->success){
            return $sessionResponse;
        }

        $uploadSessionId = $sessionResponse->data->id;
        $fileName = $file->getPathname();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$uploadSessionId}";

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => "OAuth {$this->accessToken}",
                    'file_offset' => 0,
                ],
                'body' => fopen($fileName, 'r'),
                'timeout' => 2,
            ]);

            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        }

        return $responseObject;
    }

    private function storeTemplateHeaderMedia($file, string $format): ?array
    {
        try {
            if (!$file) {
                return null;
            }

            $storage = Setting::where('key', 'storage_system')->first()->value ?? 'local';
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $safeName = uniqid('template_', true) . ($extension ? ".{$extension}" : '');

            if ($storage === 'local') {
                $storedPath = Storage::disk('local')->putFileAs('public', $file, $safeName);
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($storedPath, '/');
            } elseif ($storage === 'aws') {
                $storedPath = $file->storeAs('uploads/media/templates/' . $this->organizationId, $safeName, 's3');
                $mediaUrl = Storage::disk('s3')->url($storedPath);
            } else {
                return null;
            }

            $chatMedia = new ChatMedia();
            $chatMedia->name = $originalName;
            $chatMedia->location = $storage === 'aws' ? 'amazon' : 'local';
            $chatMedia->path = $mediaUrl;
            $chatMedia->type = $file->getMimeType();
            $chatMedia->size = $file->getSize();
            $chatMedia->created_at = now();
            $chatMedia->save();

            return [
                'media_id' => $chatMedia->id,
                'url' => $mediaUrl,
                'type' => strtoupper($format),
                'name' => $originalName,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to store template header media locally', [
                'error' => $e->getMessage(),
                'format' => $format,
            ]);

            return null;
        }
    }

    /**
     * Create Chat and ChatLog records from a successful WhatsApp API response.
     * Extracted from sendTemplateMessage for reuse in batch processing.
     *
     * @return array{chat: Chat, responseObject: object}
     */
    public function createChatLogFromResponse(
        object $responseObject,
        Contact $contact,
        ?int $userId,
        ?int $organizationId,
        ?string $metadata,
        $mediaId,
        ?int $campaignId,
        array $flowStubs = [],
        ?string $billingSource = null,
        ?int $billingSourceId = null
    ): array {
        $metadata = $this->attachTemplateBillingMetadata($metadata, $campaignId, $billingSource, $billingSourceId);

        $chat = Chat::create([
            'organization_id' => $organizationId ?? $contact->organization_id,
            'wam_id' => $responseObject->data->messages[0]->id,
            'contact_id' => $contact->id,
            'type' => 'outbound',
            'user_id' => $userId,
            'metadata' => $metadata,
            'media_id' => $mediaId,
            'status' => isset($responseObject->data->messages[0]->message_status)
                ? $responseObject->data->messages[0]->message_status
                : 'sent',
            'created_at' => now(),
        ]);

        $this->recordConversationWindowForOutbound($chat, $contact);

        $responseObject->data->chat = $chat;

        $chatlogId = ChatLog::insertGetId([
            'contact_id' => $contact->id,
            'entity_type' => 'chat',
            'entity_id' => $chat->id,
            'created_at' => now(),
        ]);

        $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
        $chatArray = array([
            'type' => 'chat',
            'value' => $chatLogArray->relatedEntities,
        ]);

        event(new NewChatEvent($chatArray, $organizationId ?? $contact->organization_id));

        // Process MetaFlow stubs
        foreach ($flowStubs as $stub) {
            if (empty($stub['flow_token']) || empty($stub['meta_flow_id'])) {
                Log::warning('MetaFlows: skipping stub — missing flow_token or meta_flow_id', [
                    'stub' => $stub,
                ]);
                continue;
            }
            $metaFlow = \Modules\MetaFlows\Models\MetaFlow::where('meta_flow_id', $stub['meta_flow_id'])
                ->where('organization_id', $organizationId ?? $contact->organization_id)
                ->first();
            if ($metaFlow) {
                \Modules\MetaFlows\Models\MetaFlowResponse::create([
                    'organization_id' => $organizationId ?? $contact->organization_id,
                    'meta_flow_id' => $metaFlow->id,
                    'contact_id' => $contact->id,
                    'flow_token' => $stub['flow_token'],
                    'response_json' => null,
                ]);
            } else {
                Log::warning('MetaFlows: no MetaFlow found for stub — stub NOT created', [
                    'meta_flow_id_searched' => $stub['meta_flow_id'],
                    'organization_id' => $organizationId ?? $contact->organization_id,
                ]);
            }
        }

        if (($responseObject->success ?? false) === true) {
            WebhookHelper::triggerWebhookEvent('message.sent', [
                'data' => $responseObject,
            ], $organizationId ?? $contact->organization_id);
        }

        return ['chat' => $chat, 'responseObject' => $responseObject];
    }

    function createResumableUploadSession($file)
    {
        $fileLength = $file->getSize();
        $fileType = $file->getMimeType();
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->appId}/uploads";

        $client = new Client();
        $responseObject = new \stdClass();

        try {
            $response = $client->request('POST', $url, [
                'form_params' => [
                    'file_length' => $fileLength,
                    'file_type' => $fileType,
                    'access_token' => $this->accessToken,
                ]
            ]);
        
            $status = $response->getStatusCode();
            $responseObject->success = true;
            $responseObject->data = json_decode($response->getBody()->getContents());
        } catch (ConnectException $e) {
            $responseObject->success = false;
            $responseObject->data = new \stdClass();
            $responseObject->data->error = new \stdClass();
            $responseObject->data->error->message = $e->getMessage();
        } catch (GuzzleException $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());

            if (isset($responseObject->data->error->error_user_msg)) {
                $responseObject->message = $responseObject->data->error->error_user_msg;
            } else {
                $responseObject->message = $responseObject->data->error->message;
            }
        } catch (Exception $e) {
            $response = $e->getResponse();
            $responseObject->success = false;
            $responseObject->data = json_decode($response->getBody()->getContents());
        }

        return $responseObject;
    }

    //Set the headers for request
    public function setHeaders()
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    // Private method to send an HTTP request
    private function sendHttpRequest($method, $url, $data = [], $headers = [])
    {
        $client = self::getSharedClient();
        $responseObject = new \stdClass();
        $correlationId = (string) \Illuminate\Support\Str::uuid();
        $startedAt = microtime(true);

        try {
            $requestOptions = [
                'headers' => $headers,
            ];

            if (isset($data) && in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
                $requestOptions['json'] = $data;
            }

            $response = $client->request($method, $url, $requestOptions);
            $rawBody = (string) $response->getBody();
            $body = $rawBody !== '' ? json_decode($rawBody) : null;

            $responseObject->correlation_id = $correlationId;
            $responseObject->http_status = $response->getStatusCode();
            $responseObject->meta_request_id = $response->getHeaderLine('x-fb-request-id') ?: null;
            $responseObject->meta_trace_id = $response->getHeaderLine('x-fb-trace-id') ?: null;
            $responseObject->duration_ms = (int) round((microtime(true) - $startedAt) * 1000);

            if (! is_object($body)) {
                $responseObject->success = false;
                $responseObject->data = (object) [
                    'error' => (object) ['message' => 'WhatsApp returned an empty or unexpected JSON response.'],
                ];
                $responseObject->message = 'WhatsApp returned an empty or unexpected JSON response.';
                $responseObject->failure_kind = 'invalid_response';
                $responseObject->json_error = $body === null ? json_last_error_msg() : 'Unexpected JSON shape';
                $responseObject->response_excerpt = mb_substr($rawBody, 0, 2000);

                $this->logHttpFailure($method, $responseObject, $responseObject->json_error, [], $data['type'] ?? null);

                return $responseObject;
            }

            // Meta can return HTTP 200 with an error object in the body
            // (e.g. rate limit 130429). Treat these as failures.
            if (isset($body->error)) {
                $responseObject->success = false;
                $responseObject->data = $body;
                $responseObject->message = $body->error->error_user_msg
                    ?? $body->error->message
                    ?? 'Unknown error';
                $responseObject->error_code = $body->error->code ?? null;
                $responseObject->error_subcode = $body->error->error_subcode ?? null;
                $responseObject->failure_kind = 'meta_error';
                $responseObject->response_excerpt = mb_substr($rawBody, 0, 2000);
                $this->logHttpFailure($method, $responseObject, null, [], $data['type'] ?? null);
            } else {
                $responseObject->success = true;
                $responseObject->data = $body;
            }
        } catch (ConnectException $e) {
            $handlerContext = $e->getHandlerContext();
            $responseObject = $this->buildHttpExceptionResponse(
                $e,
                null,
                '',
                $correlationId,
                $startedAt,
                'connection_error'
            );
            $responseObject->curl_errno = $handlerContext['errno'] ?? null;
            $responseObject->curl_error = $handlerContext['error'] ?? null;
            $this->logHttpFailure($method, $responseObject, null, $handlerContext, $data['type'] ?? null);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $rawBody = $response ? (string) $response->getBody() : '';
            $responseObject = $this->buildHttpExceptionResponse(
                $e,
                $response,
                $rawBody,
                $correlationId,
                $startedAt,
                $response ? 'http_error' : 'delivery_unknown'
            );
            $handlerContext = $e->getHandlerContext();
            $jsonError = $rawBody !== '' && json_decode($rawBody) === null ? json_last_error_msg() : null;
            $responseObject->curl_errno = $handlerContext['errno'] ?? null;
            $responseObject->curl_error = $handlerContext['error'] ?? null;
            $responseObject->json_error = $jsonError;
            $this->logHttpFailure(
                $method,
                $responseObject,
                $jsonError,
                $handlerContext,
                $data['type'] ?? null
            );
        } catch (GuzzleException $e) {
            $responseObject = $this->buildHttpExceptionResponse(
                $e,
                null,
                '',
                $correlationId,
                $startedAt,
                'guzzle_error'
            );
            $this->logHttpFailure($method, $responseObject, null, [], $data['type'] ?? null);
        } catch (\Throwable $e) {
            $responseObject = $this->buildHttpExceptionResponse(
                $e,
                null,
                '',
                $correlationId,
                $startedAt,
                'unexpected_error'
            );
            $this->logHttpFailure($method, $responseObject, null, [], $data['type'] ?? null);
        }

        return $responseObject;
    }

    private function buildHttpExceptionResponse(
        \Throwable $exception,
        $response,
        string $rawBody,
        string $correlationId,
        float $startedAt,
        string $failureKind
    ): \stdClass {
        $decodedBody = $rawBody !== '' ? json_decode($rawBody) : null;
        $message = $decodedBody?->error?->error_user_msg
            ?? $decodedBody?->error?->message
            ?? $decodedBody?->message
            ?? $exception->getMessage()
            ?? 'WhatsApp request failed.';

        $result = new \stdClass();
        $result->success = false;
        $result->correlation_id = $correlationId;
        $result->http_status = $response?->getStatusCode();
        $result->meta_request_id = $response?->getHeaderLine('x-fb-request-id') ?: null;
        $result->meta_trace_id = $response?->getHeaderLine('x-fb-trace-id') ?: null;
        $result->duration_ms = (int) round((microtime(true) - $startedAt) * 1000);
        $result->message = $message;
        $result->error_code = $decodedBody?->error?->code ?? null;
        $result->error_subcode = $decodedBody?->error?->error_subcode ?? null;
        $result->exception_class = get_class($exception);
        $result->failure_kind = $failureKind;
        $result->response_excerpt = mb_substr($rawBody, 0, 2000);
        $result->data = $decodedBody ?? (object) [
            'error' => (object) ['message' => $message],
        ];

        return $result;
    }

    private function logHttpFailure(
        string $method,
        \stdClass $response,
        ?string $jsonError = null,
        array $handlerContext = [],
        ?string $messageType = null
    ): void {
        Log::error('WhatsApp HTTP request failed', [
            'correlation_id' => $response->correlation_id ?? null,
            'organization_id' => $this->organizationId,
            'phone_number_id' => $this->phoneNumberId,
            'method' => $method,
            'message_type' => $messageType,
            'failure_kind' => $response->failure_kind ?? 'unknown',
            'http_status' => $response->http_status ?? null,
            'error_code' => $response->error_code ?? null,
            'error_subcode' => $response->error_subcode ?? null,
            'message' => $response->message ?? 'Unknown error',
            'meta_request_id' => $response->meta_request_id ?? null,
            'meta_trace_id' => $response->meta_trace_id ?? null,
            'exception_class' => $response->exception_class ?? null,
            'duration_ms' => $response->duration_ms ?? null,
            'json_error' => $jsonError,
            'curl_errno' => $handlerContext['errno'] ?? null,
            'curl_error' => $handlerContext['error'] ?? null,
            'connect_time' => $handlerContext['connect_time'] ?? null,
            'total_time' => $handlerContext['total_time'] ?? null,
            'response_excerpt' => $response->response_excerpt ?? null,
        ]);
    }
}
