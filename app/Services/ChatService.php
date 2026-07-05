<?php

namespace App\Services;

use App\Http\Resources\ContactResource;
use App\Helpers\CustomHelper;
use App\Models\Chat;
use App\Models\ChatArchive;
use App\Models\Addon;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\ChatMedia;
use App\Models\ChatTicket;
use App\Events\NewChatEvent;
use App\Models\ContactField;
use App\Models\Organization;
use App\Models\OrganizationChatStat;
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

    private function chatListRowCount(
        Contact $contact,
        $searchTerm,
        bool $ticketingActive,
        $ticketState,
        string $sortDirection,
        string $role,
        bool $allowAgentsToViewAllChats,
        array $labelFilters,
        bool $assignedToMe,
        ?int $assignedToFilter,
        bool $unreadOnly
    ): int {
        $hasFilters = filled($searchTerm)
            || $ticketState !== 'all'
            || $assignedToMe
            || $assignedToFilter !== null
            || $unreadOnly
            || !empty($labelFilters)
            || ($role === 'agent' && !$allowAgentsToViewAllChats);

        if (!$hasFilters) {
            $stats = OrganizationChatStat::where('organization_id', $this->organizationId)->first();

            if ($stats) {
                return (int) $stats->conversations_count;
            }

            return (int) Cache::remember("chat_list_count:org:{$this->organizationId}", 600, function () use ($contact) {
                return $contact->contactsWithChatsCount($this->organizationId, null, false, 'all');
            });
        }

        $cacheKey = 'chat_list_count:'.sha1(json_encode([
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
            $unreadOnly,
            auth()->id(),
        ]));

        return (int) Cache::remember($cacheKey, 60, function () use (
            $contact,
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
        ) {
            return $contact->contactsWithChatsCount(
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
        });
    }

    private function globalUnreadCount(): int
    {
        return (int) Cache::remember("org_{$this->organizationId}_global_unread", 60, function () {
            return Chat::where('organization_id', $this->organizationId)
                ->where('type', 'inbound')
                ->whereNull('deleted_at')
                ->where('is_read', 0)
                ->count();
        });
    }

    private function initializeWhatsappService()
    {
        if ($this->whatsappService) {
            return;
        }

        $config = Organization::where('id', $this->organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    private function getWhatsappService(): WhatsappService
    {
        $this->initializeWhatsappService();

        return $this->whatsappService;
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

        $rowCount = $this->chatListRowCount(
            $contact,
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

        // Direct query without an expensive COUNT(*) in the paginator.
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
            $unreadOnly,
            $rowCount
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
                $unreadMessages = $this->globalUnreadCount();

                $contactFields = ContactField::where('organization_id', $this->organizationId)
                    ->whereNull('deleted_at')
                    ->orderBy('position')
                    ->get();

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
                    'contact' => $contact ? ContactResource::make($contact)->resolve() : null,
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
                'contactFields' => ContactField::where('organization_id', $this->organizationId)
                    ->whereNull('deleted_at')
                    ->orderBy('position')
                    ->get(),
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
        return DB::table('teams')
            ->select('teams.user_id')
            ->leftJoin('chat_tickets', function ($join) use ($organizationId) {
                $join->on('teams.user_id', '=', 'chat_tickets.assigned_to')
                    ->where('chat_tickets.organization_id', '=', $organizationId)
                    ->where('chat_tickets.status', '=', 'open');
            })
            ->where('teams.organization_id', $organizationId)
            ->whereNull('teams.deleted_at')
            ->groupBy('teams.user_id')
            ->orderByRaw('COUNT(chat_tickets.id) ASC')
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

        try {
            \App\Jobs\SendAssignmentEmailJob::dispatch($organizationId, $assignedTo, $contactId);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch assignment email: ' . $e->getMessage());
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
        $ticket->organization_id = $organizationId;
        $ticket->contact_id = $contactId;
        $ticket->status = 'open';
        $ticket->updated_at = now();
        $ticket->created_at = now();

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
            $existingTicket = ChatTicket::where('contact_id', $contactId)
                ->where('organization_id', $organizationId)
                ->first();
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
                $this->assignTicketToSpecificAgent($contactId, $organizationId);
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


    private function assignTicketToSpecificAgent($contactId, $organizationId)
    {
        $ticket = ChatTicket::where('contact_id', $contactId)
            ->where('organization_id', $organizationId)
            ->first();
        $isNewTicket = false;
        $wasClosed = false;

        if (!$ticket) {
            // Create a new ticket
            $ticket = new ChatTicket();
            $ticket->organization_id = $organizationId;
            $ticket->contact_id = $contactId;
            $ticket->created_at = now();
            $ticket->updated_at = now();

            $isNewTicket = true;
        } else {
            // Check if ticket was closed
            $wasClosed = ($ticket->status === 'closed');
        }

        $ticket->status = 'open';

        // 1. Get the list of agents assigned to this organization
        $agents = DB::table('assigned_agents')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->pluck('user_id')
            ->toArray();

        if (!empty($agents)) {
            // 2. Find the last ticket assigned among these agents
            $lastTicket = ChatTicket::whereIn('assigned_to', $agents)
                ->where('organization_id', $organizationId)
                ->latest()
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

        try {
            \App\Jobs\SendAssignmentEmailJob::dispatch($organizationId, $ticket->assigned_to, $contactId);
        } catch (\Exception $e) {
            Log::error('Failed to dispatch assignment email: ' . $e->getMessage());
        }

        return $ticket;
    }

    public function sendMessage(object $request)
    {
        $userId = $request->user_id ?? $this->getUserId();

        if ($request->type === 'text') {
            return $this->getWhatsappService()->sendMessage($request->uuid, $request->message, $userId);
        } elseif (in_array($request->type, ['interactive buttons', 'interactive call to action url', 'interactive list'])) {
            $buttons = $request->buttons ?? [];
            $header = $request->header ?? [];
            $footer = $request->footer ?? null;
            $buttonLabel = $request->button_label ?? null;

            return $this->getWhatsappService()->sendMessage(
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

            $this->getWhatsappService()->sendMedia($request->uuid, $request->type, $fileName, $mediaFilePath, $mediaUrl, $location);
        }
    }

    // Send a Meta WhatsApp Flow to a contact from the chat window
    public function sendMetaFlowMessage(object $request, string $contactUuid): \stdClass
    {
        // Log everything received from the chat UI so we can trace issues end-to-end
        Log::info('ChatService: sendMetaFlowMessage request received', [
            'contact_uuid'   => $contactUuid,
            'meta_flow_uuid' => $request->meta_flow_uuid ?? null,
            'body'           => $request->body ?? null,
            'cta'            => $request->cta  ?? null,
            'organization_id'=> $this->organizationId,
        ]);

        $result = new \stdClass();

        $contact = Contact::where('uuid', $contactUuid)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$contact) {
            $result->success = false;
            $result->message = 'Contact not found.';
            return $result;
        }

        if ((bool) ($contact->is_blocked ?? false)) {
            $result->success = false;
            $result->message = 'Contact is blocked.';
            return $result;
        }

        $conversationCheck = (new SubscriptionUsageService())->canUse($this->organizationId, SubscriptionUsageService::CONVERSATIONS);
        if (! $conversationCheck['allowed']) {
            $result->success = false;
            $result->message = $conversationCheck['message'];
            return $result;
        }

        $flow = \Modules\MetaFlows\Models\MetaFlow::where('uuid', $request->meta_flow_uuid)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$flow) {
            $result->success = false;
            $result->message = 'Meta Flow not found.';
            return $result;
        }

        $options = [
            'body'   => $request->body ?? 'Please complete the form.',
            'cta'    => $request->cta  ?? 'Open',
            'header' => $flow->name,
            // 'screen' is intentionally omitted — MetaFlowService::resolveFirstScreenId()
            // reads the correct first screen ID from the flow's stored asset_content JSON.
            // Hardcoding a screen name here would break any flow whose first screen is not 'INTRO'.
        ];

        $sendResult = \Modules\MetaFlows\Services\MetaFlowService::fromOrganization($this->organizationId)
            ->sendFlowToContact($flow, $contact, $options);

        if (!($sendResult['success'] ?? false)) {
            $result->success = false;
            $result->message = $sendResult['message'] ?? 'Failed to send flow via Meta API.';
            return $result;
        }

        // Build the metadata the chat bubble will render
        $chatMetadata = [
            'type'       => 'interactive',
            'flow_name'  => $flow->name,
            'flow_uuid'  => $flow->uuid,
            'flow_token' => $sendResult['flow_token'],
            'interactive' => [
                'type' => 'flow',
                'body' => ['text' => $options['body']],
                'action' => [
                    'name'       => 'flow',
                    'parameters' => [
                        'flow_id'  => $flow->meta_flow_id,
                        'flow_cta' => $options['cta'],
                    ],
                ],
            ],
        ];

        $userId = $this->getUserId();

        $chat = Chat::create([
            'organization_id' => $this->organizationId,
            'wam_id'          => $sendResult['data']->messages[0]->id ?? null,
            'contact_id'      => $contact->id,
            'type'            => 'outbound',
            'user_id'         => $userId,
            'metadata'        => json_encode($chatMetadata),
            'status'          => 'delivered',
            'created_at'      => now(),
        ]);

        (new SubscriptionUsageService())->ensureConversationWindowForInbound(
            (int) $this->organizationId,
            (int) $contact->id,
            (int) $chat->id
        );

        $chatlogId = ChatLog::insertGetId([
            'contact_id'  => $contact->id,
            'entity_type' => 'chat',
            'entity_id'   => $chat->id,
            'created_at'  => now(),
        ]);

        $chatLogEntry = ChatLog::where('id', $chatlogId)->whereNull('deleted_at')->first();

        event(new NewChatEvent(
            [['type' => 'chat', 'value' => $chatLogEntry->relatedEntities]],
            $contact->organization_id
        ));

        $result->success = true;
        return $result;
    }

    public function sendTemplateMessage(object $request, $uuid)
    {
        $userId = $request->user_id ?? $this->getUserId();

        $template = Template::where('uuid', $request->template)->first();
        $contact = Contact::where('uuid', $uuid)->first();

        // Extract media_id from request if already uploaded
        $mediaId = $request->media_id ?? null;
        $defaultHeaderMedia = $this->resolveTemplateDefaultHeaderMedia($template);

        if (isset($request->header['format']) && in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])) {
            $header = $request->header;

            if (isset($request->header['parameters']) && $request->header['parameters']) {
                $metadata['header']['format'] = $header['format'];
                $metadata['header']['parameters'] = [];

                foreach ($request->header['parameters'] as $key => $parameter) {
                    if (($parameter['selection'] ?? null) === 'upload' && !empty($parameter['value'])) {
                        $upload = $this->storeTemplateHeaderUpload($parameter['value']);
                        $mediaUrl = $upload['url'];
                        $mediaName = $upload['name'];
                        $mediaId = $upload['media_id'];
                    } else {
                        $mediaUrl = $defaultHeaderMedia['url']
                            ?? $parameter['value']
                            ?? $parameter['url']
                            ?? null;
                        $mediaName = $defaultHeaderMedia['name']
                            ?? $parameter['filename']
                            ?? $parameter['name']
                            ?? null;
                        $mediaId = $defaultHeaderMedia['media_id'] ?? $mediaId;
                    }

                    $metadata['header']['parameters'][] = [
                        'type' => $parameter['type'],
                        'selection' => ($parameter['selection'] ?? null) === 'upload' ? 'upload' : ($defaultHeaderMedia ? 'default' : ($parameter['selection'] ?? null)),
                        'value' => $mediaUrl,
                        'url' => $mediaUrl,
                        'filename' => $mediaName,
                        'name' => $mediaName,
                    ];
                }
            }
        } else {
            $metadata['header'] = $request->header ?? null;
        }

        if (!isset($metadata['header'])) {
            $metadata['header'] = $request->header ?? null;
        }

        $metadata['body'] = $request->body ?? ['text' => '', 'parameters' => []];
        $metadata['footer'] = $request->footer ?? ['text' => null];
        $metadata['buttons'] = $request->buttons ?? [];
        $metadata['media'] = $mediaId;
        $this->applyTemplateDefaultHeaderMedia($metadata, $template);
        $mediaId = $metadata['media'] ?? $mediaId;

        //Build Template to send
        $template = $this->buildTemplate($template->name, $template->language, json_decode(json_encode($metadata)), $contact);

        return $this->getWhatsappService()->sendTemplateMessage(
            $contact->uuid,
            $template,
            $userId,
            NULL,
            $mediaId,
            null,
            null,
            null,
            $request->billing_source ?? null,
            is_numeric($request->billing_source_id ?? null) ? (int) $request->billing_source_id : null
        );
    }

    public function sendTemplateToPhone(object $request)
    {
        // Clean phone number of non-digit characters
        $phone = preg_replace('/\D+/', '', $request->phone);

        if (empty($phone)) {
            return (object) [
                'success' => false,
                'message' => 'phone number is invalid',
            ];
        }

        // Try to find existing contact, otherwise create new one
        $contact = Contact::where('organization_id', $this->organizationId)
            ->where(function ($query) use ($phone) {
                $query->where('contacts.phone', $phone)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), '-', ''), ' ', ''), '(', '') = ?", [$phone]);
            })
            ->first();

        if (! $contact) {
            $contact = Contact::create([
                'organization_id' => $this->organizationId,
                'phone' => $phone,
                'created_by' => $this->getUserId() ?: 0,
            ]);
        }

        // Load the template
        $templateRef = (string) $request->template;
        $template = Template::where('organization_id', $this->organizationId)
            ->where(function ($query) use ($templateRef) {
                $query->where('uuid', $templateRef);

                if (ctype_digit($templateRef)) {
                    $query->orWhere('id', (int) $templateRef);
                }
            })
            ->first();

        if (! $template) {
            return (object) [
                'success' => false,
                'message' => 'template not found.',
            ];
        }

        try {
            $metadata = json_decode($template->metadata, true) ?? [];

            if ($this->isCarouselTemplateMetadata($metadata)) {
                $templatePayload = $this->buildCarouselTemplateRequest((int) $template->id, $contact, $template);
            } else {
                $sendMetadata = $this->buildDefaultTemplateSendMetadata($template, $metadata);
                $sendMetadata = $this->applyTemplateVariableOverrides(
                    $sendMetadata,
                    $request->input('variables', [])
                );

                if ($request->hasFile('header_media') && $this->metadataHasMediaHeader($sendMetadata)) {
                    $upload = $this->storeTemplateHeaderUpload($request->file('header_media'));
                    $mediaId = $upload['media_id'];
                    $sendMetadata = $this->applyUploadedHeaderMediaToMetadata($sendMetadata, $upload['url'], $upload['name']);
                }

                $this->applyTemplateDefaultHeaderMedia($sendMetadata, $template);

                $templatePayload = $this->buildTemplate(
                    $template->name,
                    $template->language,
                    json_decode(json_encode($sendMetadata)),
                    $contact
                );
            }
        } catch (\Throwable $e) {
            Log::error('Send template to phone failed while building payload', [
                'error' => $e->getMessage(),
                'template_id' => $template->id,
                'contact_id' => $contact->id,
            ]);

            return (object) [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return $this->getWhatsappService()->sendTemplateMessage(
            $contact->uuid,
            $templatePayload,
            $this->getUserId(),
            null,
            $mediaId ?? null,
            $contact,
            null,
            $template
        );
    }

    private function applyTemplateVariableOverrides(array $metadata, array $variables): array
    {
        foreach (['header', 'body'] as $component) {
            foreach (($variables[$component] ?? []) as $index => $override) {
                $existingParameter = $metadata[$component]['parameters'][$index] ?? null;

                if (!is_array($override) || !is_array($existingParameter)) {
                    continue;
                }

                if ($component === 'header' && strtolower((string) ($existingParameter['type'] ?? '')) !== 'text') {
                    continue;
                }

                $metadata[$component]['parameters'][$index] = array_merge(
                    $existingParameter,
                    [
                        'type' => 'text',
                        'selection' => $override['selection'] ?? 'static',
                        'value' => $override['value'] ?? '',
                    ]
                );
            }

            if (!empty($metadata[$component]['parameters'])) {
                ksort($metadata[$component]['parameters']);
                $metadata[$component]['parameters'] = array_values($metadata[$component]['parameters']);
            }
        }

        foreach (($variables['buttons'] ?? []) as $buttonIndex => $buttonVariables) {
            if (!is_array($buttonVariables) || !isset($metadata['buttons'][$buttonIndex])) {
                continue;
            }

            foreach ($buttonVariables as $parameterIndex => $override) {
                if (
                    !is_array($override)
                    || !isset($metadata['buttons'][$buttonIndex]['parameters'][$parameterIndex])
                ) {
                    continue;
                }

                $metadata['buttons'][$buttonIndex]['parameters'][$parameterIndex] = [
                    'type' => $override['selection'] ?? 'static',
                    'value' => $override['value'] ?? '',
                ];
            }

            if (!empty($metadata['buttons'][$buttonIndex]['parameters'])) {
                ksort($metadata['buttons'][$buttonIndex]['parameters']);
                $metadata['buttons'][$buttonIndex]['parameters'] = array_values(
                    $metadata['buttons'][$buttonIndex]['parameters']
                );
            }
        }

        return $metadata;
    }

    private function metadataHasMediaHeader(array $metadata): bool
    {
        $format = strtoupper((string) ($metadata['header']['format'] ?? ''));

        return in_array($format, ['IMAGE', 'DOCUMENT', 'VIDEO'], true);
    }

    private function applyUploadedHeaderMediaToMetadata(array $metadata, string $mediaUrl, ?string $mediaName = null): array
    {
        $format = strtoupper((string) ($metadata['header']['format'] ?? ''));

        $metadata['header']['parameters'] = [[
            'type' => $format,
            'selection' => 'upload',
            'value' => $mediaUrl,
            'url' => $mediaUrl,
            'filename' => $mediaName,
            'name' => $mediaName,
        ]];
        $metadata['media'] = null;

        return $metadata;
    }

    private function storeTemplateHeaderUpload($file): array
    {
        $storage = Setting::where('key', 'storage_system')->value('value') ?: 'local';
        $fileName = $file->getClientOriginalName();
        $contentType = $file->getMimeType();
        $mediaSize = $file->getSize();

        if ($storage === 'aws') {
            $uploadedFile = $file->store('uploads/media/sent/' . $this->organizationId, 's3');
            $mediaUrl = Storage::disk('s3')->url($uploadedFile);
        } else {
            $storedPath = $file->store('public', 'local');
            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($storedPath, '/');
        }

        $chatMedia = ChatMedia::create([
            'name' => $fileName,
            'location' => $storage === 'aws' ? 'amazon' : 'local',
            'path' => $mediaUrl,
            'type' => $contentType,
            'size' => $mediaSize,
            'created_at' => now(),
        ]);

        return [
            'media_id' => $chatMedia->id,
            'url' => $mediaUrl,
            'name' => $fileName,
        ];
    }

    private function buildDefaultTemplateSendMetadata(Template $template, array $metadata): array
    {
        $sendMetadata = [
            'header' => null,
            'body' => ['text' => '', 'parameters' => []],
            'footer' => ['text' => null],
            'buttons' => [],
            'media' => null,
        ];

        $components = $metadata['components'] ?? [];

        if (empty($components)) {
            $sendMetadata['header'] = $metadata['header'] ?? null;
            $sendMetadata['body'] = $metadata['body'] ?? ['text' => '', 'parameters' => []];
            $sendMetadata['footer'] = $metadata['footer'] ?? ['text' => null];
            $sendMetadata['buttons'] = $metadata['buttons'] ?? [];
            $sendMetadata['media'] = $metadata['media'] ?? null;

            return $this->fillFlatTemplateMetadataDefaults($sendMetadata, $template);
        }

        foreach ($components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));

            if ($type === 'HEADER') {
                $sendMetadata['header'] = $this->buildDefaultHeaderMetadata($component);
            } elseif ($type === 'BODY') {
                $sendMetadata['body'] = $this->buildDefaultBodyMetadata($component);
            } elseif ($type === 'FOOTER') {
                $sendMetadata['footer'] = [
                    'text' => $component['text'] ?? null,
                ];
            } elseif ($type === 'BUTTONS') {
                $sendMetadata['buttons'] = $this->buildDefaultButtonsMetadata($component['buttons'] ?? []);
            }
        }

        return $sendMetadata;
    }

    private function fillFlatTemplateMetadataDefaults(array $metadata, Template $template): array
    {
        if (isset($metadata['header']['format'])) {
            $format = strtoupper((string) $metadata['header']['format']);
            $text = $metadata['header']['text'] ?? '';

            if ($format === 'TEXT' && empty($metadata['header']['parameters'])) {
                $examples = $this->normalizeTemplateExamples($metadata['header']['example'] ?? []);
                $metadata['header']['parameters'] = $this->buildStaticTextParameters($text, $examples);
            }
        }

        if (isset($metadata['body']) && empty($metadata['body']['parameters'])) {
            $bodyText = $metadata['body']['text'] ?? '';
            $examples = $this->normalizeTemplateExamples($metadata['body']['example'] ?? []);
            $metadata['body']['parameters'] = $this->buildStaticTextParameters($bodyText, $examples);
        }

        $metadata['buttons'] = $this->buildDefaultButtonsMetadata($metadata['buttons'] ?? []);

        return $metadata;
    }

    private function buildDefaultHeaderMetadata(array $component): ?array
    {
        $format = strtoupper((string) ($component['format'] ?? ''));

        if ($format === '') {
            return null;
        }

        $header = [
            'format' => $format,
            'text' => $component['text'] ?? '',
            'parameters' => [],
        ];

        if ($format === 'TEXT') {
            $examples = $this->normalizeTemplateExamples($component['example']['header_text'] ?? []);
            $header['parameters'] = $this->buildStaticTextParameters($component['text'] ?? '', $examples);
        }

        return $header;
    }

    private function buildDefaultBodyMetadata(array $component): array
    {
        $examples = $this->normalizeTemplateExamples($component['example']['body_text'] ?? []);

        return [
            'text' => $component['text'] ?? '',
            'parameters' => $this->buildStaticTextParameters($component['text'] ?? '', $examples),
        ];
    }

    private function buildDefaultButtonsMetadata(array $buttons): array
    {
        $sendButtons = [];

        foreach ($buttons as $button) {
            $type = strtoupper((string) ($button['type'] ?? ''));
            $sendButton = $button;
            $sendButton['type'] = $type;
            $sendButton['parameters'] = [];

            if ($type === 'URL' && $this->countTemplatePlaceholders($button['url'] ?? '') > 0) {
                $sendButton['parameters'][] = [
                    'type' => 'static',
                    'value' => $this->resolveDefaultUrlButtonValue($button),
                ];
            } elseif ($type === 'COPY_CODE') {
                $example = $button['example'] ?? null;
                $sendButton['parameters'][] = [
                    'type' => 'static',
                    'value' => is_array($example) ? (string) reset($example) : (string) ($example ?: 'N/A'),
                ];
            }

            $sendButtons[] = $sendButton;
        }

        return $sendButtons;
    }

    private function buildStaticTextParameters(string $text, array $examples): array
    {
        $count = $this->countTemplatePlaceholders($text);
        $parameters = [];

        for ($index = 0; $index < $count; $index++) {
            $value = $examples[$index] ?? 'N/A';

            if ($value === null || trim((string) $value) === '') {
                $value = 'N/A';
            }

            $parameters[] = [
                'type' => 'text',
                'selection' => 'static',
                'value' => (string) $value,
            ];
        }

        return $parameters;
    }

    private function normalizeTemplateExamples($examples): array
    {
        if (!is_array($examples)) {
            return $examples === null || $examples === '' ? [] : [(string) $examples];
        }

        if (isset($examples[0]) && is_array($examples[0])) {
            $examples = $examples[0];
        }

        return array_values(array_map(static function ($value) {
            return is_array($value) ? (string) reset($value) : (string) $value;
        }, $examples));
    }

    private function countTemplatePlaceholders(?string $text): int
    {
        if (!$text) {
            return 0;
        }

        preg_match_all('/{{\s*\d+\s*}}/', $text, $matches);

        return count($matches[0]);
    }

    private function resolveDefaultUrlButtonValue(array $button): string
    {
        $example = $button['example'] ?? null;
        $value = is_array($example) ? (string) reset($example) : (string) ($example ?? '');

        if ($value === '') {
            return 'N/A';
        }

        $url = (string) ($button['url'] ?? '');
        $placeholderPosition = strpos($url, '{{');

        if ($placeholderPosition !== false) {
            $prefix = substr($url, 0, $placeholderPosition);

            if ($prefix !== '' && str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
            }
        }

        return $value !== '' ? $value : 'N/A';
    }

    private function isCarouselTemplateMetadata(array $metadata): bool
    {
        if (($metadata['template_type'] ?? null) === 'carousel' || !empty($metadata['carousel_cards'] ?? [])) {
            return true;
        }

        foreach (($metadata['components'] ?? []) as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === 'CAROUSEL') {
                return true;
            }
        }

        return false;
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
            'messages' => $this->markRevokedMessagesForDisplay(array_reverse($chats), $contactId),
            'hasMoreMessages' => $chatLogs->hasMorePages(),
            'nextPage' => $chatLogs->currentPage() + 1
        ];
    }

    private function markRevokedMessagesForDisplay(array $chats, int $contactId): array
    {
        $revokeEvents = array_merge(
            $this->extractRevokeEventsFromChatRows($chats),
            $this->getStoredRevokeEventsForContact($contactId)
        );

        if (empty($revokeEvents)) {
            return $chats;
        }

        $revokedByOriginalId = [];
        foreach ($revokeEvents as $event) {
            $originalMessageId = $event['original_message_id'] ?? null;
            if (! is_string($originalMessageId) || trim($originalMessageId) === '') {
                continue;
            }

            $revokedByOriginalId[$originalMessageId] = $event;
        }

        if (empty($revokedByOriginalId)) {
            return $chats;
        }

        foreach ($chats as $index => $row) {
            $chat = $row[0]['value'] ?? null;
            if (! $chat instanceof Chat && ! $chat instanceof ChatArchive) {
                continue;
            }

            if (empty($chat->wam_id)) {
                continue;
            }

            $event = $revokedByOriginalId[$chat->wam_id] ?? null;
            if (! $event) {
                continue;
            }

            $metadata = $this->decodeChatMetadataForDisplay($chat->metadata);
            $metadata['revoked'] = [
                'is_revoked' => true,
                'revoked_at' => $event['timestamp'] ?? time(),
                'revoke_wam_id' => $event['revoke_wam_id'] ?? null,
            ];

            $chat->metadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $chats[$index][0]['value'] = $chat;
        }

        return $chats;
    }

    private function getStoredRevokeEventsForContact(int $contactId): array
    {
        return Chat::where('organization_id', $this->organizationId)
            ->where('contact_id', $contactId)
            ->whereNull('deleted_at')
            ->where('metadata', 'like', '%"type":"revoke"%')
            ->get(['wam_id', 'metadata'])
            ->map(function (Chat $chat) {
                return $this->extractRevokeEventFromChat($chat);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function extractRevokeEventsFromChatRows(array $chats): array
    {
        $events = [];

        foreach ($chats as $row) {
            $chat = $row[0]['value'] ?? null;
            if (! $chat instanceof Chat && ! $chat instanceof ChatArchive) {
                continue;
            }

            $event = $this->extractRevokeEventFromChat($chat);
            if ($event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function extractRevokeEventFromChat($chat): ?array
    {
        $metadata = $this->decodeChatMetadataForDisplay($chat->metadata);

        if (($metadata['type'] ?? null) !== 'revoke') {
            return null;
        }

        $originalMessageId = $metadata['revoke']['original_message_id']
            ?? $metadata['raw']['revoke']['original_message_id']
            ?? null;

        if (! is_string($originalMessageId) || trim($originalMessageId) === '') {
            return null;
        }

        return [
            'original_message_id' => $originalMessageId,
            'timestamp' => $metadata['timestamp'] ?? $metadata['raw']['timestamp'] ?? time(),
            'revoke_wam_id' => $chat->wam_id,
        ];
    }

    private function decodeChatMetadataForDisplay($metadata): array
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return is_array($decoded) ? $decoded : [];
    }
}
