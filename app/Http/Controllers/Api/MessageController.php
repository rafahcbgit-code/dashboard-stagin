<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Template;
use App\Services\ChatService;
use App\Services\PhoneService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    private $chatService;

    public function __construct()
    {
        // ChatService will be initialized per request with organization_id
    }

    /**
     * Send a message (text, media, buttons)
     * POST /api/v1/chats/{contact_uuid}/messages
     * 
     * Request Types:
     * 1. Text: { "type": "text", "message": "Hello" }
     * 2. Image: { "type": "image", "file": <binary>, "caption": "Check this" }
     * 3. Video: { "type": "video", "file": <binary>, "caption": "Watch this" }
     * 4. Audio: { "type": "audio", "file": <binary> }
     * 5. Document: { "type": "document", "file": <binary> }
     * 6. Buttons: { "type": "interactive", "message": "Choose", "buttons": [...] }
     */
    public function sendMessage(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id; 
            // Initialize ChatService with organization
            $this->chatService = new ChatService($organizationId);

            // Basic validation
            $validator = Validator::make($request->all(), [
                'type' => 'required|in:text,image,video,audio,document,interactive',
                'message' => 'required_if:type,text,interactive',
                'file' => 'required_if:type,image,video,audio,document|file',
                'caption' => 'nullable|string|max:1024',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }
           
            // Check subscription
            if (!SubscriptionService::isSubscriptionActive($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please renew or subscribe to a plan to continue!'
                ], 403);
            }

            // Check message limit
            if (SubscriptionService::isSubscriptionFeatureLimitReached($organizationId, 'message_limit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have reached your message limit. Please upgrade your plan!'
                ], 403);
            }

            // Check WhatsApp connection
            if (!$this->isWhatsAppConnected($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please setup your WhatsApp account first!'
                ], 403);
            }

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Route to appropriate handler based on type
            $messageType = $request->input('type');

            if ($messageType === 'text') {
                return $this->handleTextMessage($request, $contact, $userId);
            } elseif (in_array($messageType, ['image', 'video', 'audio', 'document'])) {
                return $this->handleMediaMessage($request, $contact, $messageType, $userId);
            } elseif ($messageType === 'interactive') {
                return $this->handleInteractiveMessage($request, $contact, $userId);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle text message
     */
    private function handleTextMessage($request, $contact, $userId)
    {
        $message = $request->input('message');

        // Send via ChatService (which uses WhatsappService)
        $response = $this->chatService->sendMessage((object)[
            'uuid' => $contact->uuid,
            'message' => $message,
            'type' => 'text',
            'user_id' => $userId
        ]);

        // Check if WhatsappService returned success
        if (isset($response->success) && $response->success === true) {
            // Get the sent message from database to return consistent format
            $sentMessage = \App\Models\Chat::where('uuid', $response->data->chat->uuid ?? null)
                ->with('media')
                ->first();

            if ($sentMessage) {
                return response()->json([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => [
                        'message' => $this->formatMessage($sentMessage)
                    ]
                ], 200);
            }

            // Fallback if message not found in DB yet
            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => [
                    'message' => [
                        'uuid' => $response->data->chat->uuid ?? null,
                        'type' => 'outbound',
                        'message_type' => 'text',
                        'status' => 'sent',
                        'is_read' => false,
                        'created_at' => now()->toIso8601String(),
                        'wam_id' => $response->data->messages[0]->id ?? null,
                        'content' => [
                            'text' => $message
                        ]
                    ]
                ]
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => $response->message ?? 'Failed to send message',
                'error' => $response->data->error ?? null
            ], 500);
        }
    }

    /**
     * Handle media message (image, video, audio, document)
     */
    private function handleMediaMessage($request, $contact, $mediaType, $userId)
    {
        // Validate file
        $validator = $this->validateMediaFile($request, $mediaType);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid file',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $organizationId = $request->organization_id;
            $file = $request->file('file');
            $caption = $request->input('caption', '');
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $file->getClientOriginalName();
            
            // Upload file based on storage system
            if ($storage === 'local') {
                $location = 'local';
                $uploadedFile = Storage::disk('local')->put('public', $file);
                $mediaFilePath = $uploadedFile;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } elseif ($storage === 'aws') {
                $location = 'amazon';
                $uploadedFile = $file->store('uploads/media/sent/' . $organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $mediaUrl = $mediaFilePath;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid storage configuration'
                ], 500);
            }

            // Initialize WhatsappService
            $config = \App\Models\Organization::where('id', $organizationId)->first()->metadata;
            $config = $config ? json_decode($config, true) : [];

            if (!isset($config['whatsapp'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'WhatsApp not configured'
                ], 403);
            }

            $whatsappService = new \App\Services\WhatsappService(
                $config['whatsapp']['access_token'] ?? null,
                config('graph.api_version'),
                $config['whatsapp']['app_id'] ?? null,
                $config['whatsapp']['phone_number_id'] ?? null,
                $config['whatsapp']['waba_id'] ?? null,
                $organizationId
            );

            // Send media via WhatsApp
            $response = $whatsappService->sendMedia(
                $contact->uuid,
                $mediaType,
                $fileName,
                $mediaFilePath,
                $mediaUrl,
                $location,
                $caption
            );

            if (isset($response->success) && $response->success === true) {
                // Get the sent message from database to return consistent format
                $sentMessage = \App\Models\Chat::where('uuid', $response->data->chat->uuid ?? null)
                    ->with('media')
                    ->first();

                if ($sentMessage) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Media sent successfully',
                        'data' => [
                            'message' => $this->formatMessage($sentMessage)
                        ]
                    ], 200);
                }

                // Fallback if message not found in DB yet
                return response()->json([
                    'success' => true,
                    'message' => 'Media sent successfully',
                    'data' => [
                        'message' => [
                            'uuid' => $response->data->chat->uuid ?? null,
                            'type' => 'outbound',
                            'message_type' => $mediaType,
                            'status' => 'sent',
                            'is_read' => false,
                            'created_at' => now()->toIso8601String(),
                            'wam_id' => $response->data->messages[0]->id ?? null,
                            'content' => [
                                'caption' => $caption,
                                'media' => [
                                    'url' => $mediaUrl,
                                    'filename' => $fileName,
                                    'type' => $file->getMimeType(),
                                    'size' => $file->getSize()
                                ]
                            ]
                        ]
                    ]
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->message ?? 'Failed to send media',
                    'error' => $response->data->error ?? null
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send media',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle interactive message (buttons)
     */
    private function handleInteractiveMessage($request, $contact, $userId)
    {
        $validator = Validator::make($request->all(), [
            'buttons' => 'required|array|min:1|max:3',
            'buttons.*.id' => 'required|string',
            'buttons.*.title' => 'required|string|max:20',
            'header' => 'nullable|string|max:60',
            'footer' => 'nullable|string|max:60',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid button configuration',
                'errors' => $validator->errors()
            ], 422);
        }

        $message = $request->input('message');
        $buttons = $request->input('buttons');
        $header = $request->input('header');
        $footer = $request->input('footer');

        // Format header for WhatsApp API
        $headerFormatted = [];
        if ($header) {
            $headerFormatted['type'] = 'text';
            $headerFormatted['text'] = $header;
        }

        // Send via ChatService
        $response = $this->chatService->sendMessage((object)[
            'uuid' => $contact->uuid,
            'message' => $message,
            'type' => 'interactive buttons',
            'buttons' => $buttons,
            'header' => $headerFormatted,
            'footer' => $footer,
            'user_id' => $userId
        ]);

        if (isset($response->success) && $response->success === true) {
            // Get the sent message from database to return consistent format
            $sentMessage = \App\Models\Chat::where('uuid', $response->data->chat->uuid ?? null)
                ->with('media')
                ->first();

            if ($sentMessage) {
                return response()->json([
                    'success' => true,
                    'message' => 'Interactive message sent successfully',
                    'data' => [
                        'message' => $this->formatMessage($sentMessage)
                    ]
                ], 200);
            }

            // Fallback if message not found in DB yet
            return response()->json([
                'success' => true,
                'message' => 'Interactive message sent successfully',
                'data' => [
                    'message' => [
                        'uuid' => $response->data->chat->uuid ?? null,
                        'type' => 'outbound',
                        'message_type' => 'interactive',
                        'status' => 'sent',
                        'is_read' => false,
                        'created_at' => now()->toIso8601String(),
                        'wam_id' => $response->data->messages[0]->id ?? null,
                        'content' => [
                            'header' => $headerFormatted,
                            'text' => $message,
                            'footer' => ['text' => $footer],
                            'buttons' => $buttons
                        ]
                    ]
                ]
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => $response->message ?? 'Failed to send interactive message',
                'error' => $response->data->error ?? null
            ], 500);
        }
    }

    public function sendTemplate(Request $request, $contactUuid){
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;
            $this->chatService = new ChatService($organizationId);

            // Validation
            $validator = Validator::make($request->all(), [
                'template_uuid' => 'required|string|exists:templates,uuid',
                'parameters' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check subscription
            if (!SubscriptionService::isSubscriptionActive($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please renew or subscribe to a plan to continue!'
                ], 403);
            }

            // Check WhatsApp connection
            if (!$this->isWhatsAppConnected($organizationId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please setup your WhatsApp account first!'
                ], 403);
            }

            // Find contact
            $contact = Contact::where('uuid', $contactUuid)
                ->where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->first();

            if (!$contact) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contact not found'
                ], 404);
            }

            // Find template
            $template = Template::where('uuid', $request->template_uuid)
                ->where('organization_id', $organizationId)
                ->where('status', 'APPROVED')
                ->whereNull('deleted_at')
                ->first();

            if (!$template) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template not found or not approved'
                ], 404);
            }

            // Send via ChatService
            $response = $this->chatService->sendTemplateMessage((object)[
                'template' => $request->template_uuid,
                'user_id' => $userId
            ], $contact->uuid);

            if (isset($response->success) && $response->success === true) {
                // Get the sent message from database to return consistent format
                $sentMessage = \App\Models\Chat::where('uuid', $response->data->chat->uuid ?? null)
                    ->with('media')
                    ->first();

                if ($sentMessage) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Template sent successfully',
                        'data' => [
                            'message' => $this->formatMessage($sentMessage)
                        ]
                    ], 200);
                }

                // Fallback if message not found in DB yet
                return response()->json([
                    'success' => true,
                    'message' => 'Template sent successfully',
                    'data' => [
                        'message' => [
                            'uuid' => $response->data->chat->uuid ?? null,
                            'type' => 'outbound',
                            'message_type' => 'template',
                            'status' => $response->data->messages[0]->message_status ?? 'sent',
                            'is_read' => false,
                            'created_at' => now()->toIso8601String(),
                            'wam_id' => $response->data->messages[0]->id ?? null,
                            'content' => [
                                'template_name' => $template->name
                            ]
                        ]
                    ]
                ], 200);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $response->message ?? 'Failed to send template',
                    'error' => $response->data->error ?? null
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Build template data with parameters
     */
    private function buildTemplateData($template, $parameters, $contact)
    {
        $templateData = [
            'name' => $template->name,
            'language' => [
                'code' => $template->language
            ]
        ];

        // Add components with parameters if provided
        if (!empty($parameters)) {
            $components = [];

            // Header parameters
            if (isset($parameters['header']) && is_array($parameters['header'])) {
                $components[] = [
                    'type' => 'header',
                    'parameters' => array_map(function($value) {
                        return ['type' => 'text', 'text' => $value];
                    }, $parameters['header'])
                ];
            }

            // Body parameters
            if (isset($parameters['body']) && is_array($parameters['body'])) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(function($value) {
                        return ['type' => 'text', 'text' => $value];
                    }, $parameters['body'])
                ];
            }

            if (!empty($components)) {
                $templateData['components'] = $components;
            }
        }

        return $templateData;
    }

    /**
     * Validate media file
     */
    private function validateMediaFile($request, $mediaType)
    {
        $rules = [];

        switch ($mediaType) {
            case 'image':
                $rules = [
                    'file' => 'required|file|mimes:jpg,jpeg,png|max:5120' // 5MB
                ];
                break;
            case 'video':
                $rules = [
                    'file' => 'required|file|mimes:mp4,3gp|max:16384' // 16MB
                ];
                break;
            case 'audio':
                $rules = [
                    'file' => 'required|file|mimes:aac,amr,mp3,m4a,ogg|max:16384' // 16MB
                ];
                break;
            case 'document':
                $rules = [
                    'file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt|max:102400' // 100MB
                ];
                break;
        }

        return Validator::make($request->all(), $rules);
    }

    /**
     * Check if WhatsApp is connected
     */
    private function isWhatsAppConnected($organizationId)
    {
        $settings = \App\Models\Organization::where('id', $organizationId)->first();
        $metadata = $settings->metadata ? json_decode($settings->metadata, true) : [];

        return isset($metadata['whatsapp']);
    }

    /**
     * Format message to match GET messages endpoint structure
     */
    private function formatMessage($message)
    {
        $formatted = [
            'uuid' => $message->uuid,
            'type' => $message->type, // inbound or outbound
            'message_type' => $message->message_type,
            'status' => $message->status,
            'is_read' => (bool) $message->is_read,
            'created_at' => $message->created_at,
            'wam_id' => $message->wam_id,
        ];

        // Format content based on message type
        switch ($message->message_type) {
            case 'text':
                $formatted['content'] = [
                    'text' => $message->text
                ];
                break;

            case 'image':
            case 'video':
            case 'audio':
            case 'document':
                $media = $message->media->first();
                $formatted['content'] = [
                    'caption' => $message->text,
                    'media' => $media ? [
                        'url' => $media->url,
                        'filename' => $media->filename,
                        'type' => $media->type,
                        'size' => $media->size
                    ] : null
                ];
                break;

            case 'sticker':
                $media = $message->media->first();
                $formatted['content'] = [
                    'media' => $media ? [
                        'url' => $media->url,
                        'filename' => $media->filename,
                        'type' => $media->type,
                        'size' => $media->size
                    ] : null
                ];
                break;

            case 'location':
                $metadata = $message->metadata ? json_decode($message->metadata, true) : [];
                $formatted['content'] = [
                    'latitude' => $metadata['latitude'] ?? null,
                    'longitude' => $metadata['longitude'] ?? null,
                    'name' => $metadata['name'] ?? null,
                    'address' => $metadata['address'] ?? null
                ];
                break;

            case 'interactive':
                $metadata = $message->metadata ? json_decode($message->metadata, true) : [];
                $formatted['content'] = [
                    'header' => $metadata['header'] ?? null,
                    'text' => $message->text,
                    'footer' => $metadata['footer'] ?? null,
                    'buttons' => $metadata['buttons'] ?? []
                ];
                break;

            case 'button':
                $metadata = $message->metadata ? json_decode($message->metadata, true) : [];
                $formatted['content'] = [
                    'text' => $message->text,
                    'payload' => $metadata['payload'] ?? null
                ];
                break;

            default:
                $formatted['content'] = [
                    'text' => $message->text
                ];
                break;
        }

        return $formatted;
    }
}