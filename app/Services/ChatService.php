<?php

namespace App\Services;

use App\Http\Resources\ContactResource;
use App\Helpers\CustomHelper;
use App\Models\Chat;
use App\Models\Addon;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\ChatMedia;
use App\Models\ChatTicket;
use App\Events\NewChatEvent;
use App\Models\ContactField;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Team;
use App\Models\Template;
use App\Models\ChatTicketLog;
use App\Traits\TemplateTrait;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ChatService
{
    use TemplateTrait;

    private $whatsappService;
    private $organizationId;

    private $throttleSeconds = 15;
    private $cacheTtl = 30;


    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
    }

    /**
     * Get authenticated user ID (supports both session and Sanctum)
     */
    private function getUserId()
    {
        // Try Sanctum first (for API)
        if (auth('sanctum')->check()) {
            return auth('sanctum')->user()->id;
        }

        // Try session (for web)
        if (auth()->check()) {
            return auth()->user()->id;
        }

        // Default to 0 (system/API)
        return 0;
    }

    private function initializeWhatsappService()
    {
        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    private function shouldThrottle($cacheKey)
    {
        $throttleKey = $cacheKey . ':throttle';
        $lastRequest = Cache::get($throttleKey);

        if ($lastRequest) {
            $elapsed = time() - $lastRequest;
            if ($elapsed < $this->throttleSeconds) {
                return [
                    'throttled' => true,
                    'wait' => $this->throttleSeconds - $elapsed,
                    'data' => Cache::get($cacheKey)
                ];
            }
        }

        return ['throttled' => false];
    }

    private function markRequestProcessed($cacheKey)
    {
        $throttleKey = $cacheKey . ':throttle';
        Cache::put($throttleKey, time(), $this->throttleSeconds);
    }

    public function getChatList($request, $uuid = null, $searchTerm = null)
    {
        $role = auth()->user()->teams[0]->role;
        $contact = new Contact;
        $ticketState = $request->status == null ? 'all' : $request->status;
        $sortDirection = $request->session()->get('chat_sort_direction') ?? 'desc';

        // Get label filters from request
        $labelFilters = $request->query('labels') ? explode(',', $request->query('labels')) : [];

        // Get assigned_to filter from request
        $assignedToFilter = $request->query('assigned_to') ? (int)$request->query('assigned_to') : null;

        // Check if unread filter is active via status
        $unreadOnly = ($ticketState === 'unread');

        $assignedToMe = ($ticketState === 'assigned_to_me');

        if ($assignedToMe || $unreadOnly) {
            $ticketState = 'all';
        }

        $config = Organization::where('id', $this->organizationId)->first();
        $agents = Team::where('organization_id', $this->organizationId)->get();
        $allowAgentsToViewAllChats = true;
        $ticketingActive = false;
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');

        // Check if tickets module has been enabled
        if ($config->metadata != NULL) {
            $settings = json_decode($config->metadata);

            if (isset($settings->tickets) && $settings->tickets->active === true) {
                $ticketingActive = true;
                $allowAgentsToViewAllChats = $settings->tickets->allow_agents_to_view_all_chats;
            }
        }

        // Direct query - NO CACHE
        $contacts = $contact->contactsWithChats(
            $this->organizationId,
            $searchTerm,
            $ticketingActive,
            $ticketState,
            $sortDirection,
            $role,
            $allowAgentsToViewAllChats,
            $labelFilters,
            $assignedToMe,
            $assignedToFilter,
            $unreadOnly
        );

        $rowCount = $contact->contactsWithChatsCount(
            $this->organizationId,
            $searchTerm,
            $ticketingActive,
            $ticketState,
            $sortDirection,
            $role,
            $allowAgentsToViewAllChats,
            $labelFilters,
            $assignedToMe,
            $assignedToFilter,
            $unreadOnly
        );

        $pusherSettings = Setting::whereIn('key', [
            'pusher_app_id',
            'pusher_app_key',
            'pusher_app_secret',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();

        $messageTemplates = Template::where('organization_id', $this->organizationId)
            ->where('deleted_at', null)
            ->where('status', 'APPROVED')
            ->get();

        $team = Team::where('organization_id', $this->organizationId)
            ->where('user_id', auth()->user()->id)
            ->first();

        if ($uuid !== null) {
            $contact = Contact::with(['lastChat', 'lastInboundChat', 'notes', 'contactGroups', 'labels'])
                ->where('uuid', $uuid)
                ->first();

            $ticket = ChatTicket::with('user')
                ->where('contact_id', $contact->id)
                ->first();

            $initialMessages = $this->getChatMessages($contact->id);

            // Mark messages as read
            Chat::where('contact_id', $contact->id)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->update(['is_read' => 1]);

            if (request()->expectsJson()) {
                return response()->json([
                    'result' => ContactResource::collection($contacts)->response()->getData(),
                ], 200);
            } else {
                $settings = json_decode($config->metadata);

                // To ensure the unread message counter is updated
                $unreadMessages = Chat::where('organization_id', $this->organizationId)
                    ->where('type', 'inbound')
                    ->where('deleted_at', NULL)
                    ->where('is_read', 0)
                    ->count();

                $contactFieldService = new ContactFieldService(session()->get('current_organization'));
                $contactFields = $contactFieldService->get($request);

                $labelColors = DB::table('labels')
                    ->join('contact_label', 'labels.id', '=', 'contact_label.label_id')
                    ->where('contact_label.contact_id', $contact->id)
                    ->select('labels.color')
                    ->pluck('color');

                return Inertia::render('User/Chat/Index', [
                    'title' => 'Chats',
                    'rows' => ContactResource::collection($contacts),
                    'simpleForm' => CustomHelper::isModuleEnabled('AI Assistant') && optional(optional($settings)->ai)->ai_chat_form_active ? false : true,
                    'rowCount' => $rowCount,
                    'filters' => request()->all(),
                    'pusherSettings' => $pusherSettings,
                    'organizationId' => $this->organizationId,
                    'state' => app()->environment(),
                    'demoNumber' => env('DEMO_NUMBER'),
                    'settings' => $config,
                    'templates' => $messageTemplates,
                    'status' => $request->status ?? 'all',
                    'chatThread' => $initialMessages['messages'],
                    'hasMoreMessages' => $initialMessages['hasMoreMessages'],
                    'nextPage' => $initialMessages['nextPage'],
                    'contact' => $contact,
                    'fields' => ContactField::where('organization_id', $this->organizationId)->where('deleted_at', null)->get(),
                    'locationSettings' => $this->getLocationSettings(),
                    'ticket' => $ticket,
                    'agents' => $agents,
                    'addon' => $aimodule,
                    'chat_sort_direction' => $sortDirection,
                    'unreadMessages' => $unreadMessages,
                    'contactFields' => $contactFields,
                    'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit'),
                    'teamRole' => $team?->role,
                    'labelColors' => $labelColors
                ]);
            }
        }

        if (request()->expectsJson()) {
            return response()->json([
                'result' => ContactResource::collection($contacts)->response()->getData(),
            ], 200);
        } else {
            $settings = json_decode($config->metadata);

            return Inertia::render('User/Chat/Index', [
                'title' => 'Chats',
                'rows' => ContactResource::collection($contacts),
                'simpleForm' => !CustomHelper::isModuleEnabled('AI Assistant') || empty($settings->ai->ai_chat_form_active),
                'rowCount' => $rowCount,
                'filters' => request()->all(),
                'pusherSettings' => $pusherSettings,
                'organizationId' => $this->organizationId,
                'state' => app()->environment(),
                'settings' => $config,
                'templates' => $messageTemplates,
                'status' => $request->status ?? 'all',
                'agents' => $agents,
                'addon' => $aimodule,
                'ticket' => array(),
                'chat_sort_direction' => $sortDirection,
                'isChatLimitReached' => SubscriptionService::isSubscriptionFeatureLimitReached($this->organizationId, 'message_limit'),
                'teamRole' => $team?->role
            ]);
        }
    }

    /**
     * Find the agent with the least number of assigned tickets
     */
    private function findLeastLoadedAgent($organizationId)
    {
        // Prefer agents from the assigned_agents table for this organization
        $assignedAgentIds = DB::table('assigned_agents')
            ->where('organization_id', $organizationId)
            ->pluck('user_id')
            ->toArray();

        if (!empty($assignedAgentIds)) {
            return Team::where('organization_id', $organizationId)
                ->whereIn('user_id', $assignedAgentIds)
                ->where('role', 'agent')
                ->where('status', 'active')
                ->withCount('tickets')
                ->whereNull('deleted_at')
                ->orderBy('tickets_count')
                ->first();
        }

        // Fallback: pick any active agent in the organization with least tickets
        return Team::where('organization_id', $organizationId)
            ->where('role', 'agent')
            ->where('status', 'active')
            ->withCount('tickets')
            ->whereNull('deleted_at')
            ->orderBy('tickets_count')
            ->first();
    }

    /**
     * Send assignment notification to the assigned agent
     */
    private function sendTicketAssignmentNotification($contactId, $assignedTo, $assignedBy, $organizationId)
    {
        if (!$assignedTo) {
            return;
        }

        try {
            $notificationService = new \App\Services\ApiNotificationService();
            $notificationService->sendAssignmentNotification(
                $contactId,
                $assignedTo,
                $assignedBy,
                $organizationId
            );
        } catch (\Exception $e) {
            Log::error('Failed to send assignment notification: ' . $e->getMessage());
        }
    }

    /**
     * Log ticket event in ChatTicketLog and ChatLog
     */
    private function logTicketEvent($contactId, $description)
    {
        $ticketId = ChatTicketLog::insertGetId([
            'contact_id' => $contactId,
            'description' => $description,
            'created_at' => now()
        ]);

        ChatLog::insert([
            'contact_id' => $contactId,
            'entity_type' => 'ticket',
            'entity_id' => $ticketId,
            'created_at' => now()
        ]);
    }

    /**
     * Assign agent to ticket based on auto-assignment setting
     */
    private function assignAgentToTicket($ticket, $organizationId, $autoassignment)
    {
        if (!$autoassignment) {
            $ticket->assigned_to = null;
            return;
        }

        $agent = $this->findLeastLoadedAgent($organizationId);
        $ticket->assigned_to = $agent ? $agent->user_id : null;
    }

    /**
     * Create a new ticket and assign agent
     */
    private function createNewTicket($contactId, $organizationId, $autoassignment)
    {
        $ticket = new ChatTicket();
        $ticket->contact_id = $contactId;
        $ticket->status = 'open';
        $ticket->updated_at = now();

        $this->assignAgentToTicket($ticket, $organizationId, $autoassignment);
        $ticket->save();

        $this->sendTicketAssignmentNotification(
            $contactId,
            $ticket->assigned_to,
            auth('sanctum')->id() ?? auth()->id() ?? 0,
            $organizationId
        );

        $this->logTicketEvent($contactId, 'Conversation was opened');

        return $ticket;
    }

    /**
     * Process existing ticket (closed or unassigned)
     */
    private function processExistingTicket($ticket, $contactId, $organizationId, $autoassignment, $reassignOnReopen)
    {
        // Only process if closed OR open but unassigned
        if ($ticket->status !== 'closed' && !($ticket->status === 'open' && $ticket->assigned_to === null)) {
            return;
        }

        $shouldLogReopen = ($ticket->status === 'closed');
        $wasClosedAndNeedReassign = ($ticket->status === 'closed' && $reassignOnReopen);

        // Reopen closed ticket
        if ($ticket->status === 'closed') {
            $ticket->status = 'open';
        }

        // Assign agent if needed
        if ($wasClosedAndNeedReassign || ($ticket->status === 'open' && $ticket->assigned_to === null)) {
            $this->assignAgentToTicket($ticket, $organizationId, $autoassignment);
        }

        $ticket->updated_at = now();
        $ticket->save();

        $this->sendTicketAssignmentNotification(
            $contactId,
            $ticket->assigned_to,
            0,
            $organizationId
        );

        if ($shouldLogReopen) {
            $this->logTicketEvent($contactId, 'Conversation was moved from closed to open');
        }
    }

    public function handleTicketAssignment($contactId)
    {
        try {
            $organizationId = $this->organizationId;

            // Get organization settings
            $settings = Organization::where('id', $organizationId)->first();
            $settings = json_decode($settings->metadata);
            $isSpecificAgentEnabled = !empty($settings->tickets->assigned_to_specific_agents ?? false);

            // Skip if ticket already exists, is open AND is already assigned
            $existingTicket = ChatTicket::where('contact_id', $contactId)->first();
            if ($existingTicket && $existingTicket->status === 'open' && $existingTicket->assigned_to !== null) {
                Log::channel('webhook')->debug('Ticket already open and assigned, skipping assignment', [
                    'contact_id' => $contactId,
                    'ticket_id' => $existingTicket->id,
                    'assigned_to' => $existingTicket->assigned_to
                ]);
                return;
            }

            // Case 1: Assign tickets to specific agents in a round-robin style
            if ($isSpecificAgentEnabled) {
                $reassignOnReopen = $settings->tickets->reassign_reopened_chats;
                $this->assignTicketToSpecificAgent($contactId, $organizationId, $reassignOnReopen);
                return;
            }

            // Case 2: Auto-assignment logic if active
            if (isset($settings->tickets) && $settings->tickets->active === true) {
                $autoassignment = $settings->tickets->auto_assignment;
                $reassignOnReopen = $settings->tickets->reassign_reopened_chats;

                $ticket = ChatTicket::where('contact_id', $contactId)->first();

                DB::transaction(function () use ($reassignOnReopen, $autoassignment, $ticket, $contactId, $organizationId) {
                    if (!$ticket) {
                        // Create a new ticket
                        $this->createNewTicket($contactId, $organizationId, $autoassignment);
                    } else {
                        // Process existing ticket (closed or open without assignment)
                        $this->processExistingTicket($ticket, $contactId, $organizationId, $autoassignment, $reassignOnReopen);
                    }
                });
            }
        } catch (\Exception $ex) {
            Log::error('Ticket assignment failed: ' . $ex->getMessage(), [
                'contact_id' => $contactId,
                'organization_id' => $this->organizationId
            ]);
        }
    }


    private function assignTicketToSpecificAgent($contactId, $organizationId, $reassignOnReopen)
    {
        return DB::transaction(function () use ($contactId, $organizationId, $reassignOnReopen) {
            $ticket = ChatTicket::where('contact_id', $contactId)->lockForUpdate()->first();
            $isNewTicket = false;
            $wasClosed = false;

            if (!$ticket) {
                // Create a new ticket
                $ticket = new ChatTicket();
                $ticket->contact_id = $contactId;
                $ticket->created_at = now();
                $isNewTicket = true;
            } else {
                // Check if ticket was closed
                $wasClosed = ($ticket->status === 'closed');
            }

            $ticket->status = 'open';

            // Only reassign if ticket is new OR if it was closed and reassignOnReopen is enabled
            $shouldReassign = !$wasClosed || $reassignOnReopen;

            if ($shouldReassign) {
                // 1. Get the list of agents assigned to this organization
                $agents = DB::table('assigned_agents')
                    ->where('organization_id', $organizationId)
                    ->orderBy('id')
                    ->pluck('user_id')
                    ->toArray();

                if (!empty($agents)) {
                    // 2. Find the last ticket assigned among these agents (with pessimistic locking to prevent race conditions)
                    // Also scope by organization using a join to ensure organization context
                    $lastTicket = ChatTicket::whereIn('assigned_to', $agents)
                        ->join('contacts', 'chat_tickets.contact_id', '=', 'contacts.id')
                        ->where('contacts.organization_id', $organizationId)
                        ->select('chat_tickets.*')
                        ->latest('chat_tickets.id')
                        ->lockForUpdate()
                        ->first();

                    if ($lastTicket) {
                        $lastIndex = array_search($lastTicket->assigned_to, $agents);
                        $nextIndex = ($lastIndex === false) ? 0 : ($lastIndex + 1) % count($agents);
                    } else {
                        $nextIndex = 0;
                    }

                    // 3. Assign the next agent in sequence
                    $ticket->assigned_to = $agents[$nextIndex];
                }
            }

            // Log appropriate event based on ticket state
            if ($isNewTicket) {
                $this->logTicketEvent($contactId, 'Conversation was opened');
            } elseif ($wasClosed) {
                $this->logTicketEvent($contactId, 'Conversation was moved from closed to open');
            }

            $ticket->updated_at = now();
            $ticket->save();

            // Send assignment notification
            try {
                $notificationService = new \App\Services\ApiNotificationService();
                $notificationService->sendAssignmentNotification(
                    $contactId,
                    $ticket->assigned_to,
                    auth('sanctum')->id() ?? auth()->id() ?? 0,
                    $organizationId
                );
            } catch (\Exception $e) {
                Log::error('Failed to send assignment notification: ' . $e->getMessage());
            }

            return $ticket;
        });
    }

    public function sendMessage(object $request)
    {
        $userId = $request->user_id ?? $this->getUserId();

        if ($request->type === 'text') {
            return $this->whatsappService->sendMessage($request->uuid, $request->message, $userId);
        } elseif (in_array($request->type, ['interactive buttons', 'interactive call to action url', 'interactive list'])) {
            $buttons = $request->buttons ?? [];
            $header = $request->header ?? [];
            $footer = $request->footer ?? null;
            $buttonLabel = $request->button_label ?? null;

            return $this->whatsappService->sendMessage(
                $request->uuid,
                $request->message,
                $userId,
                $request->type,
                $buttons,
                $header,
                $footer,
                $buttonLabel
            );
        } else {
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if ($storage === 'local') {
                $location = 'local';
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } else if ($storage === 'aws') {
                $location = 'amazon';
                $file = $request->file('file');
                $filePath = 'uploads/media/received/'  . $this->organizationId . '/' . $fileName;
                $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                $mediaFilePath = Storage::disk('s3')->url($uploadedFile);
                $mediaUrl = $mediaFilePath;
            }

            $this->whatsappService->sendMedia($request->uuid, $request->type, $fileName, $mediaFilePath, $mediaUrl, $location);
        }
    }

    public function sendTemplateMessage(object $request, $uuid)
    {
        $userId = $request->user_id ?? $this->getUserId();

        $template = Template::where('uuid', $request->template)->first();
        $contact = Contact::where('uuid', $uuid)->first();

        // Extract media_id from request if already uploaded
        $mediaId = $request->media_id ?? null;

        if (isset($request->header['format']) && in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            $header = $request->header;

            if (isset($request->header['parameters']) && $request->header['parameters']) {
                $metadata['header']['format'] = $header['format'];
                $metadata['header']['parameters'] = [];

                foreach ($request->header['parameters'] as $key => $parameter) {
                    if ($parameter['selection'] === 'upload') {
                        // This is now only for web version (when file object is passed)
                        $storage = Setting::where('key', 'storage_system')->first()->value;
                        $fileName = $parameter['value']->getClientOriginalName();
                        $fileContent = $parameter['value'];

                        if ($storage === 'local') {
                            $file = Storage::disk('local')->put('public', $fileContent);
                            $mediaFilePath = $file;

                            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                        } else if ($storage === 'aws') {
                            $file = $parameter['value'];
                            $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
                            $mediaFilePath = Storage::disk('s3')->url($uploadedFile);

                            $mediaUrl = $mediaFilePath;
                        }

                        $contentType = $this->getContentTypeFromUrl($mediaUrl);
                        $mediaSize = $this->getMediaSizeInBytesFromUrl($mediaUrl);

                        //save media
                        $chatMedia = new ChatMedia;
                        $chatMedia->name = $fileName;
                        $chatMedia->location = $storage == 'aws' ? 'amazon' : 'local';
                        $chatMedia->path = $mediaUrl;
                        $chatMedia->type = $contentType;
                        $chatMedia->size = $mediaSize;
                        $chatMedia->created_at = now();
                        $chatMedia->save();

                        $mediaId = $chatMedia->id;
                    } else {
                        // For API (when URL is passed) - use the URL directly
                        $mediaUrl = $parameter['value'];
                    }

                    $metadata['header']['parameters'][] = [
                        'type' => $parameter['type'],
                        'selection' => $parameter['selection'],
                        'value' => $mediaUrl,
                    ];
                }
            }
        } else {
            $metadata['header'] = $request->header ?? null;
        }

        $metadata['body'] = $request->body ?? ['text' => '', 'parameters' => []];
        $metadata['footer'] = $request->footer ?? ['text' => null];
        $metadata['buttons'] = $request->buttons ?? [];
        $metadata['media'] = $mediaId;

        //Build Template to send
        $template = $this->buildTemplate($template->name, $template->language, json_decode(json_encode($metadata)), $contact);

        return $this->whatsappService->sendTemplateMessage($contact->uuid, $template, $userId, NULL, $mediaId);
    }

    /**
     * Send a carousel template message to a contact.
     *
     * This is intentionally separate from sendTemplateMessage() because the
     * carousel payload structure is fundamentally different from standard
     * templates — it has nested card components rather than a flat
     * header/body/buttons layout.
     *
     * @param  string  $contactUuid   The UUID of the contact to send to
     * @param  int     $templateId    The Template model ID (resolved from UUID by the caller)
     * @return object                 The response object from WhatsappService
     */
    public function sendCarouselTemplateMessage(string $contactUuid, int $templateId): object
    {
        
        $contact  = Contact::where('uuid', $contactUuid)->first();
        $template = Template::where('id', $templateId)->first();
        $userId  = $this->getUserId();

        // Build the carousel payload using the trait method
        $templateContent = $this->buildCarouselTemplateRequest($templateId, $contact);

        $enrichedPayload = $this->whatsappService->enrichCarouselWithMediaIds($templateContent);

        // Send via WhatsappService — reuses the same sendTemplateMessage method
        // since it just POSTs whatever templateContent it receives to Meta.
        // We pass null for campaignId and mediaId since carousel templates
        // don't use those fields.
        $responseObject = $this->whatsappService->sendTemplateMessage(
            $contactUuid,
            $enrichedPayload,
            $userId,
            null,  // campaignId
            null   // mediaId
        );

        return $responseObject;
    }

    public function clearMessage($uuid)
    {
        Chat::where('uuid', $uuid)
            ->update([
                'deleted_by' => auth()->user()->id,
                'deleted_at' => now()
            ]);
    }

    public function clearContactChat($uuid)
    {
        $contact = Contact::with('lastChat')->where('uuid', $uuid)->firstOrFail();
        Chat::where('contact_id', $contact->id)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        ChatLog::where('contact_id', $contact->id)->where('entity_type', 'chat')->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        $chat = Chat::with('contact', 'media')->where('id', $contact->lastChat->id)->first();

        //event(new NewChatEvent($chat, $contact->organization_id));
    }

    private function getContentTypeFromUrl($url)
    {
        try {
            // Make a HEAD request to fetch headers only
            $response = Http::head($url);

            // Check if the Content-Type header is present
            if ($response->hasHeader('Content-Type')) {
                return $response->header('Content-Type');
            }

            return null;
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error fetching headers: ' . $e->getMessage());
            return null;
        }
    }

    private function getMediaSizeInBytesFromUrl($url)
    {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);

        if ($imageContent !== false) {
            return strlen($imageContent);
        }

        return null;
    }

    private function getLocationSettings()
    {
        // Retrieve the settings for the current organization
        $settings = Organization::where('id', $this->organizationId)->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['contacts'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $location = $metadata['contacts']['location'];

                // Now, you have the location value available
                return $location;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }

    public function getChatMessages($contactId, $page = 1, $perPage = 10)
    {
        $chatLogs = ChatLog::where('contact_id', $contactId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $chats = [];
        foreach ($chatLogs as $chatLog) {
            // Only add if relatedEntities exists
            if ($chatLog->relatedEntities !== null) {
                $chats[] = array([
                    'type' => $chatLog->entity_type,
                    'value' => $chatLog->relatedEntities
                ]);
            }
        }

        return [
            'messages' => array_reverse($chats),
            'hasMoreMessages' => $chatLogs->hasMorePages(),
            'nextPage' => $chatLogs->currentPage() + 1
        ];
    }
}
