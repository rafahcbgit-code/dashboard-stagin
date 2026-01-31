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

            // PERMISSION CHECK: Agents can only send messages to assigned or unassigned chats
            $userRole = \App\Models\Team::where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->value('role');

            if ($userRole === 'agent') {
                $ticket = \App\Models\ChatTicket::where('contact_id', $contact->id)->first();
                if ($ticket && $ticket->assigned_to && $ticket->assigned_to !== $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This conversation is assigned to another agent'
                    ], 403);
                }
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
            // Get the sent message from database with media relationship
            $sentMessage = \App\Models\Chat::with('media')
                ->where('uuid', $response->data->chat->uuid ?? null)
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
                        'is_read' => (bool)false,
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
                $uploadedFile = Storage::disk('local')->putFileAs('public', $file, $fileName);
                $mediaFilePath = $uploadedFile;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } elseif ($storage === 'aws') {
                $location = 'amazon';
                $s3Path = 'uploads/media/sent/' . $organizationId . '/' . $fileName;
                Storage::disk('s3')->put($s3Path, fopen($file->getPathname(), 'r'), [
                    'ContentType' => $this->normalizeMimeType($file),
                    'ACL' => 'public-read'
                ]);
                $uploadedFile = $s3Path;
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
                // Get the sent message from database with media relationship
                $sentMessage = \App\Models\Chat::with('media')
                    ->where('uuid', $response->data->chat->uuid ?? null)
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
                            'is_read' => (bool)false,
                            'created_at' => now()->toIso8601String(),
                            'wam_id' => $response->data->messages[0]->id ?? null,
                            'content' => [
                                'caption' => $caption,
                                'media' => [
                                    'url' => $mediaUrl,
                                    'filename' => $fileName,
                                        'type' => $this->normalizeMimeType($file),
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
     * Send template message
     * POST /api/v1/chats/{contact_uuid}/messages/template
     * 
     * For templates with IMAGE/VIDEO/DOCUMENT header:
     * Content-Type: multipart/form-data
     * 
     * Fields:
     * - template_uuid: string (required)
     * - header_selection: "upload" or "link" (required if template has media header)
     * - header_file: file (required if header_selection=upload)
     * - header_link: url (required if header_selection=link)
     * - body_params: JSON array of parameter values (optional)
     * 
     * For templates with TEXT header or no header:
     * Content-Type: application/json
     * 
     * Body:
     * {
     *     "template_uuid": "template-123",
     *     "header": { "parameters": [{"type": "text", "text": "John"}] },
     *     "body": { "parameters": [{"type": "text", "text": "#12345"}] }
     * }
     */
    public function sendTemplate(Request $request, $contactUuid)
    {
        try {
            $organizationId = $request->organization_id;
            $userId = $request->user('sanctum')->id;
            $this->chatService = new ChatService($organizationId);

            // Basic validation
            $validator = Validator::make($request->all(), [
                'template_uuid' => 'required|string|exists:templates,uuid',
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

            // PERMISSION CHECK SHI: Agents can only send templates to assigned or unassigned chats
            $userRole = \App\Models\Team::where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->value('role');

            if ($userRole === 'agent') {
                $checkTicket = \App\Models\ChatTicket::where('contact_id', $contact->id)->first();
                if ($checkTicket && $checkTicket->assigned_to && $checkTicket->assigned_to !== $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This conversation is assigned to another agent'
                    ], 403);
                }
            }

            // Build template request - NEW METHOD
            $templateRequest = $this->buildTemplateRequestWithMedia($request, $template, $organizationId);

            // Send via ChatService
            $response = $this->chatService->sendTemplateMessage(
                $templateRequest,
                $contact->uuid
            );

            if (isset($response->success) && $response->success === true) {
                // Get the sent message from database
                $sentMessage = \App\Models\Chat::with('media')
                    ->where('uuid', $response->data->chat->uuid ?? null)
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

                // Fallback
                return response()->json([
                    'success' => true,
                    'message' => 'Template sent successfully',
                    'data' => [
                        'message' => [
                            'uuid' => $response->data->chat->uuid ?? null,
                            'type' => 'outbound',
                            'message_type' => 'template',
                            'status' => $response->data->messages[0]->message_status ?? 'sent',
                            'is_read' => (bool)false,
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
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ], 500);
        }
    }

    /**
     * Build template request with media upload support (matches web version)
     */
    private function buildTemplateRequestWithMedia($request, $template, $organizationId)
    {
        $metadata = json_decode($template->metadata, true);
        $components = $metadata['components'] ?? [];

        $templateRequest = (object)[
            'template' => $template->uuid,
            'user_id' => $request->user('sanctum')->id,
            'header' => null,    //  Initialize all properties
            'body' => null,      //  Initialize all properties
            'footer' => null,    //  Initialize all properties
            'buttons' => null,   //  Initialize all properties
        ];

        $mediaId = null; // Track media ID

        // Find header component
        $headerComponent = $this->findComponent($components, 'HEADER');

        if ($headerComponent) {
            $format = $headerComponent['format'] ?? 'TEXT';

            // Check if this is a media header (IMAGE, VIDEO, DOCUMENT)
            if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                $headerData = $this->buildMediaHeader($request, $format, $organizationId);
                $templateRequest->header = $headerData;
                $mediaId = $headerData['media_id'] ?? null; // Extract media ID
            } else {
                // TEXT header
                $templateRequest->header = $this->buildTextHeader($request, $headerComponent);
            }
        } else {
            $templateRequest->header = [
                'format' => null,
                'parameters' => null
            ];
        }

        // Build body
        $bodyComponent = $this->findComponent($components, 'BODY');
        if ($bodyComponent) {
            $templateRequest->body = $this->buildBody($request, $bodyComponent);
        } else {
            //  Set empty body structure if no body component
            $templateRequest->body = [
                'text' => '',
                'parameters' => []
            ];
        }

        // Build footer -  Always set, even if null
        $footerComponent = $this->findComponent($components, 'FOOTER');
        if ($footerComponent && isset($footerComponent['text'])) {
            $templateRequest->footer = [
                'text' => $footerComponent['text']
            ];
        } else {
            //  Set null footer structure if no footer
            $templateRequest->footer = [
                'text' => null
            ];
        }

        // Build buttons -  Always set, even if empty
        $buttonsComponent = $this->findComponent($components, 'BUTTONS');
        if ($buttonsComponent && isset($buttonsComponent['buttons'])) {
            $templateRequest->buttons = $buttonsComponent['buttons'];
        } else {
            //  Set empty buttons if no buttons component
            $templateRequest->buttons = [];
        }

        // Add media ID if present (for ChatService to use)
        if ($mediaId) {
            $templateRequest->media_id = $mediaId;
        }

        return $templateRequest;
    }

    /**
     * Build media header (IMAGE, VIDEO, DOCUMENT)
     * Supports both file upload and URL
     */
    private function buildMediaHeader($request, $format, $organizationId)
    {
        $selection = $request->input('header_selection', 'link');

        $header = [
            'format' => $format, // Keep uppercase: IMAGE, VIDEO, DOCUMENT
            'parameters' => null
        ];

        if ($selection === 'upload') {
            if (!$request->hasFile('header_file')) {
                throw new \Exception('header_file is required when header_selection is upload');
            }

            $file = $request->file('header_file');
            $this->validateHeaderFile($file, $format);

            // Upload file
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $file->getClientOriginalName();

            if ($storage === 'local') {
                $uploadedFile = Storage::disk('local')->putFileAs('public', $file, $fileName);
                $mediaFilePath = $uploadedFile;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } elseif ($storage === 'aws') {
                $s3Path = 'uploads/media/sent/' . $organizationId . '/' . $fileName;
                Storage::disk('s3')->put($s3Path, fopen($file->getPathname(), 'r'), [
                    'ContentType' => $this->normalizeMimeType($file),
                    'ACL' => 'public-read'
                ]);
                $uploadedFile = $s3Path;
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $mediaUrl = $mediaFilePath;
            } else {
                throw new \Exception('Invalid storage configuration');
            }

            // Save media
            $chatMedia = new \App\Models\ChatMedia;
            $chatMedia->name = $fileName;
            $chatMedia->location = $storage === 'aws' ? 'amazon' : 'local';
            $chatMedia->path = $mediaUrl;
            $chatMedia->type = $this->normalizeMimeType($file);
            $chatMedia->size = $file->getSize();
            $chatMedia->created_at = now();
            $chatMedia->save();

            // Build parameter in format ChatService expects
            $header['parameters'] = [[
                'type' => $format, // Keep UPPERCASE: IMAGE, VIDEO, DOCUMENT
                'selection' => 'static', // Use 'static' since we already have URL
                'value' => $mediaUrl
            ]];

            $header['media_id'] = $chatMedia->id;
        } else {
            $headerLink = $request->input('header_link');

            if (!$headerLink) {
                throw new \Exception('header_link is required when header_selection is link');
            }

            if (!filter_var($headerLink, FILTER_VALIDATE_URL)) {
                throw new \Exception('header_link must be a valid URL');
            }

            // Build parameter in format ChatService expects
            $header['parameters'] = [[
                'type' => $format, // Keep UPPERCASE: IMAGE, VIDEO, DOCUMENT
                'selection' => 'static',
                'value' => $headerLink
            ]];

            $header['media_id'] = null;
        }

        return $header;
    }

    /**
     * Build text header
     */
    private function buildTextHeader($request, $headerComponent)
    {
        $header = [
            'format' => 'TEXT',
            'text' => $headerComponent['text'] ?? null,
            'parameters' => []
        ];

        // Get header parameters from request
        // Support both JSON and form data
        $headerParams = $request->input('header_params');

        if ($headerParams) {
            if (is_string($headerParams)) {
                $headerParams = json_decode($headerParams, true);
            }

            if (is_array($headerParams)) {
                foreach ($headerParams as $param) {
                    $header['parameters'][] = [
                        'type' => 'text',
                        'selection' => 'static',
                        'value' => $param
                    ];
                }
            }
        }

        return $header;
    }

    /**
     * Build body
     */
    private function buildBody($request, $bodyComponent)
    {
        $body = [
            'text' => $bodyComponent['text'] ?? '',
            'parameters' => []
        ];

        // Get body parameters from request
        // Support both JSON and form data
        $bodyParams = $request->input('body_params');

        if ($bodyParams) {
            if (is_string($bodyParams)) {
                $bodyParams = json_decode($bodyParams, true);
            }

            if (is_array($bodyParams)) {
                foreach ($bodyParams as $param) {
                    $body['parameters'][] = [
                        'type' => 'text',
                        'selection' => 'static',
                        'value' => $param
                    ];
                }
            }
        }

        return $body;
    }

    /**
     * Validate header file based on format
     */
    private function validateHeaderFile($file, $format)
    {
        $rules = [];

        switch ($format) {
            case 'IMAGE':
                $rules = [
                    'header_file' => 'required|file|mimes:jpg,jpeg,png|max:5120' // 5MB
                ];
                break;
            case 'VIDEO':
                $rules = [
                    'header_file' => 'required|file|mimes:mp4,3gp,mov|max:65536' // 64MB
                ];
                break;
            case 'DOCUMENT':
                $rules = [
                    'header_file' => 'required|file|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx|max:102400' // 100MB
                ];
                break;
        }

        $validator = Validator::make(['header_file' => $file], $rules);

        if ($validator->fails()) {
            throw new \Exception($validator->errors()->first());
        }
    }

    /**
     * Normalize mime type for uploaded files.
     * If the detected mime is 'application/octet-stream' try to infer
     * a more specific mime from the file extension to satisfy WhatsApp.
     */
    private function normalizeMimeType($file)
    {
        $mime = $file->getMimeType();

        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }

        $ext = strtolower($file->getClientOriginalExtension() ?? '');

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

        return $mime ?: 'application/octet-stream';
    }

    /**
     * Find component by type
     */
    private function findComponent($components, $type)
    {
        foreach ($components as $component) {
            if ($component['type'] === $type) {
                return $component;
            }
        }
        return null;
    }

    /**
     * Format header parameters for ChatService
     */
    private function formatHeaderParameters($parameters)
    {
        if (empty($parameters)) {
            return [];
        }

        return array_map(function ($param) {
            $formatted = [
                'type' => $param['type'],
                'selection' => 'static', // API always uses static values
            ];

            if ($param['type'] === 'text') {
                $formatted['value'] = $param['text'];
            } elseif ($param['type'] === 'image') {
                $formatted['value'] = $param['image']['link'];
            } elseif ($param['type'] === 'video') {
                $formatted['value'] = $param['video']['link'];
            } elseif ($param['type'] === 'document') {
                $formatted['value'] = $param['document']['link'];
            }

            return $formatted;
        }, $parameters);
    }

    /**
     * Format body parameters for ChatService
     */
    private function formatBodyParameters($parameters)
    {
        if (empty($parameters)) {
            return [];
        }

        return array_map(function ($param) {
            return [
                'type' => 'text',
                'selection' => 'static',
                'value' => $param['text']
            ];
        }, $parameters);
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
            // Get the sent message from database with media relationship
            $sentMessage = \App\Models\Chat::with('media')
                ->where('uuid', $response->data->chat->uuid ?? null)
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
                        'is_read' => (bool) false,
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
                    'file' => 'required|file|mimes:mp4,3gp,mov|max:65536' // 64MB
                ];
                break;
            case 'audio':
                $rules = [
                    'file' => 'required|file|mimes:aac,amr,mp3,m4a,mp4,ogg|max:16384' // 16MB
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
    private function formatMessage($chat)
    {
        $metadata = json_decode($chat->metadata, true);
        $messageType = $metadata['type'] ?? 'text';

        $message = [
            'uuid' => $chat->uuid,
            'type' => $chat->type, // inbound or outbound
            'message_type' => $messageType,
            'status' => $chat->status,
            'is_read' => (bool) $chat->is_read,
            'created_at' => $chat->created_at,
            'wam_id' => $chat->wam_id,
        ];

        // Text message
        if ($messageType === 'text' && isset($metadata['text']['body'])) {
            $message['content'] = [
                'text' => $metadata['text']['body']
            ];
        }

        // Image
        elseif ($messageType === 'image') {
            $message['content'] = [
                'caption' => $metadata['image']['caption'] ?? null,
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Video
        elseif ($messageType === 'video') {
            $message['content'] = [
                'caption' => $metadata['video']['caption'] ?? null,
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Audio
        elseif ($messageType === 'audio') {
            $message['content'] = [
                'media' => $this->getMediaInfo($chat->media),
                'transcript' => $metadata['transcript'] ?? null
            ];
        }

        // Document
        elseif ($messageType === 'document') {
            $message['content'] = [
                'filename' => $metadata['document']['filename'] ?? 'Document',
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Sticker
        elseif ($messageType === 'sticker') {
            $message['content'] = [
                'media' => $this->getMediaInfo($chat->media)
            ];
        }

        // Location
        elseif ($messageType === 'location') {
            $message['content'] = [
                'latitude' => $metadata['location']['latitude'] ?? null,
                'longitude' => $metadata['location']['longitude'] ?? null,
                'name' => $metadata['location']['name'] ?? null,
                'address' => $metadata['location']['address'] ?? null,
            ];
        }

        // Interactive (buttons/list)
        elseif ($messageType === 'interactive') {
            $message['content'] = [
                'header' => $metadata['header'] ?? null,
                'text' => $metadata['text']['body'] ?? $metadata['body']['text'] ?? null,
                'footer' => $metadata['footer']['text'] ?? null,
                'buttons' => $metadata['buttons'] ?? null,
            ];
        }

        // Button reply
        elseif ($messageType === 'button') {
            $message['content'] = [
                'text' => $metadata['button']['text'] ?? null,
                'payload' => $metadata['button']['payload'] ?? null,
            ];
        }

        // Default fallback
        else {
            $message['content'] = [
                'text' => $chat->text ?? null
            ];
        }

        return $message;
    }

    /**
     * Get media info from Chat model relationship
     */
    private function getMediaInfo($mediaRelation)
    {
        if (!$mediaRelation) {
            return null;
        }

        // If it's a collection, get the first item
        $media = is_object($mediaRelation) && method_exists($mediaRelation, 'first')
            ? $mediaRelation->first()
            : $mediaRelation;

        if (!$media) {
            return null;
        }

        return [
            'url' => $media->path ?? null,
            'filename' => $media->name ?? null,
            'type' => $media->type ?? null,
            'size' => $media->size ?? null,
        ];
    }
}
