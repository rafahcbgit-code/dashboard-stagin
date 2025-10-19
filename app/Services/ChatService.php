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

    private $throttleSeconds = 15;  // Request throttle window
    private $cacheTtl = 300;        // Cache duration in seconds (5 minutes)


    public function __construct($organizationId)
    {
        $this->organizationId = $organizationId;
        $this->initializeWhatsappService();
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

        // Generate unique cache key - now including page number
        $page = $request->get('page', 1);
        $labelKey = !empty($labelFilters) ? md5(json_encode($labelFilters)) : 'none';
        $searchKey = $searchTerm ? md5($searchTerm) : 'none';
        $cacheKey = sprintf(
            'chat_list:%d:%s:%s:%s:%s:%s:%d',
            $this->organizationId,
            $ticketState,
            $searchKey,
            $labelKey,
            $sortDirection,
            $role,
            $page
        );
        
        // Check throttle for AJAX requests only
        if (request()->expectsJson() && $uuid === null) {
            $throttleCheck = $this->shouldThrottle($cacheKey);
            
            if ($throttleCheck['throttled']) {
                return response()->json([
                    'status' => 'wait',
                    'wait_seconds' => $throttleCheck['wait'],
                    'message' => 'Please wait before refreshing'
                ], 200);
            }
        }

        $config = Organization::where('id', $this->organizationId)->first();
        $agents = Team::where('organization_id', $this->organizationId)->get();
        $allowAgentsToViewAllChats = true;
        $ticketingActive = false;
        $aimodule = CustomHelper::isModuleEnabled('AI Assistant');

        //Check if tickets module has been enabled
        if($config->metadata != NULL){
            $settings = json_decode($config->metadata);

            if(isset($settings->tickets) && $settings->tickets->active === true){
                $ticketingActive = true;

                //Check for chats that don't have corresponding chat ticket rows
                $allContactsForTickets = $contact->contactsWithChats($this->organizationId, NULL);
                
                foreach($allContactsForTickets as $contactItem){
                    ChatTicket::firstOrCreate(
                        ['contact_id' => $contactItem->id],
                        [
                            'assigned_to' => null,
                            'status' => 'open',
                            'updated_at' => now(),
                        ]
                    );
                }

                //Check if agents can view all chats
                $allowAgentsToViewAllChats = $settings->tickets->allow_agents_to_view_all_chats;
            }
        }

        // Get or cache the contact list for THIS specific page
        $cachedData = Cache::remember($cacheKey, $this->cacheTtl, function() use (
            $contact, 
            $searchTerm, 
            $ticketingActive, 
            $ticketState, 
            $sortDirection, 
            $role, 
            $allowAgentsToViewAllChats, 
            $labelFilters
        ) {
            $contacts = $contact->contactsWithChats(
                $this->organizationId, 
                $searchTerm, 
                $ticketingActive, 
                $ticketState, 
                $sortDirection, 
                $role, 
                $allowAgentsToViewAllChats,
                $labelFilters
            );
            
            $rowCount = $contact->contactsWithChatsCount(
                $this->organizationId, 
                $searchTerm, 
                $ticketingActive, 
                $ticketState, 
                $sortDirection, 
                $role, 
                $allowAgentsToViewAllChats,
                $labelFilters
            );

            return [
                'contacts' => $contacts,
                'rowCount' => $rowCount
            ];
        });

        $contacts = $cachedData['contacts'];
        $rowCount = $cachedData['rowCount'];

        $pusherSettings = Setting::whereIn('key', [
            'pusher_app_id',
            'pusher_app_key',
            'pusher_app_secret',
            'pusher_app_cluster',
        ])->pluck('value', 'key')->toArray();

        $perPage = 10; // Number of items per page
        $totalContacts = $rowCount; // Total number of contacts
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

                //To ensure the unread message counter is updated
                $unreadMessages = Chat::where('organization_id', $this->organizationId)
                    ->where('type', 'inbound')
                    ->where('deleted_at', NULL)
                    ->where('is_read', 0)
                    ->count();

                     $contactFieldService = new ContactFieldService(session()->get('current_organization'));
                    $contactFields= $contactFieldService->get($request);

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
                    'teamRole' => $team?->role  
                ]);
            }
        }

        // Mark this request as processed (for throttling)
        if (request()->expectsJson()) {
            $this->markRequestProcessed($cacheKey);
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

    public function handleTicketAssignment($contactId)
        {

            try{
                $organizationId = $this->organizationId;
                // Get organization settings
                $settings = Organization::where('id', $organizationId)->first();
                $settings = json_decode($settings->metadata);
               $isSpecificAgentEnabled = !empty($settings->tickets->assigned_to_specific_agents ?? false);

                // Case 1: Assign tickets to specific agents in a round-robin style
                if ($isSpecificAgentEnabled) {
                    $this->assignTicketToSpecificAgent($contactId, $organizationId);
                    return ;
                }


                // Case 2: Auto-assignment logic if active
                if (isset($settings->tickets) && $settings->tickets->active === true) {
                    $autoassignment    = $settings->tickets->auto_assignment;
                    $reassignOnReopen  = $settings->tickets->reassign_reopened_chats;

                    $ticket = ChatTicket::where('contact_id', $contactId)->first();

                    DB::transaction(function () use ($reassignOnReopen, $autoassignment, $ticket, $contactId, $organizationId, $isSpecificAgentEnabled) {
                        if (!$ticket) {
                            // Create a new ticket if none exists
                            $ticket = new ChatTicket();
                            $ticket->contact_id = $contactId;
                            $ticket->status = 'open';
                            $ticket->updated_at = now();

                            if ($autoassignment) {
                                // Find the agent with the least number of assigned tickets
                                $agent = Team::where('organization_id', $organizationId)
                                    ->withCount('tickets')
                                    ->whereNull('deleted_at')
                                    ->orderBy('tickets_count')
                                    ->first();

                                // Assign the ticket to the agent
                                if ($agent) {
                                    $ticket->assigned_to = $agent->user_id;
                                    } else {
                                        $ticket->assigned_to = null;
                                    }
                            } else {
                                $ticket->assigned_to = null;
                            }

                            $ticket->save();

                            $ticketId = ChatTicketLog::insertGetId([
                                'contact_id' => $contactId,
                                'description' => 'Conversation was opened',
                                'created_at' => now()
                            ]);

                            ChatLog::insert([
                                'contact_id' => $contactId,
                                'entity_type' => 'ticket',
                                'entity_id' => $ticketId,
                                'created_at' => now()
                            ]);
                        } else {
                            // Reopen the ticket if closed and reassignment is enabled
                            if ($ticket->status === 'closed') {
                            if($isSpecificAgentEnabled){
                                    $this->assignTicketToSpecificAgent($contactId, $organizationId);
                                    return ;
                                }
                            elseif ($reassignOnReopen) {
                                    if ($autoassignment) {
                                        $agent = Team::where('organization_id', $organizationId)
                                            ->withCount('tickets')
                                            ->whereNull('deleted_at')
                                            ->orderBy('tickets_count')
                                            ->first();

                                        $ticket->assigned_to = $agent->user_id;
                                    } else {
                                        $ticket->assigned_to = null;
                                    }
                                }

                                $ticket->status = 'open';
                                $ticket->save();

                                $ticketId = ChatTicketLog::insertGetId([
                                    'contact_id' => $contactId,
                                    'description' => 'Conversation was moved from closed to open',
                                    'created_at' => now()
                                ]);

                                ChatLog::insert([
                                    'contact_id' => $contactId,
                                    'entity_type' => 'ticket',
                                    'entity_id' => $ticketId,
                                    'created_at' => now()
                                ]);
                            }
                        }
                    });
                }
            }catch(\Exception $ex){
                
            }
        }
        

    private function assignTicketToSpecificAgent($contactId, $organizationId)
        {
            $ticket = ChatTicket::where('contact_id', $contactId)->first();

            if (!$ticket) {
                // Create a new ticket
                $ticket = new ChatTicket();
                $ticket->contact_id = $contactId;
                $ticket->status = 'open';
                $ticket->created_at = now();
            }

            // 1. Get the list of agents assigned to this organization
            $agents = DB::table('assigned_agents')
                ->where('organization_id', $organizationId)
                ->orderBy('id')
                ->pluck('user_id')
                ->toArray();

            if (!empty($agents)) {
                // 2. Find the last ticket assigned among these agents
                $lastTicket = ChatTicket::whereIn('assigned_to', $agents)
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

            $ticket->updated_at = now();
            $ticket->save();

            return $ticket;
        }

    public function sendMessage(object $request)
    {
        if($request->type === 'text'){
            return $this->whatsappService->sendMessage($request->uuid, $request->message, auth()->user()->id);
        } else {
            $storage = Setting::where('key', 'storage_system')->first()->value;
            $fileName = $request->file('file')->getClientOriginalName();
            $fileContent = $request->file('file');

            if($storage === 'local'){
                $location = 'local';
                $file = Storage::disk('local')->put('public', $fileContent);
                $mediaFilePath = $file;
                $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
            } else if($storage === 'aws') {
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
        $template = Template::where('uuid', $request->template)->first();
        $contact = Contact::where('uuid', $uuid)->first();
        $mediaId = null;

        if(in_array($request->header['format'], ['IMAGE', 'DOCUMENT', 'VIDEO'])){
            $header = $request->header;
            
            if ($request->header['parameters']) {
                $metadata['header']['format'] = $header['format'];
                $metadata['header']['parameters'] = [];
        
                foreach ($request->header['parameters'] as $key => $parameter) {
                    if ($parameter['selection'] === 'upload') {
                        $storage = Setting::where('key', 'storage_system')->first()->value;
                        $fileName = $parameter['value']->getClientOriginalName();
                        $fileContent = $parameter['value'];

                        if($storage === 'local'){
                            $file = Storage::disk('local')->put('public', $fileContent);
                            $mediaFilePath = $file;
            
                            $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                        } else if($storage === 'aws') {
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
            $metadata['header'] = $request->header;
        }

        $metadata['body'] = $request->body;
        $metadata['footer'] = $request->footer;
        $metadata['buttons'] = $request->buttons;
        $metadata['media'] = $mediaId;

        //Build Template to send
        $template = $this->buildTemplate($template->name, $template->language, json_decode(json_encode($metadata)), $contact);
        
        return $this->whatsappService->sendTemplateMessage($contact->uuid, $template, auth()->user()->id, NULL, $mediaId);
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

        $chat = Chat::with('contact','media')->where('id', $contact->lastChat->id)->first();

        //event(new NewChatEvent($chat, $contact->organization_id));
    }

    private function getContentTypeFromUrl($url) {
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

    private function getMediaSizeInBytesFromUrl($url) {
        $url = ltrim($url, '/');
        $imageContent = file_get_contents($url);
    
        if ($imageContent !== false) {
            return strlen($imageContent);
        }
    
        return null;
    }

    private function getLocationSettings(){
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
            $chats[] = array([
                'type' => $chatLog->entity_type,
                'value' => $chatLog->relatedEntities
            ]);
        }

        return [
            'messages' => array_reverse($chats),
            'hasMoreMessages' => $chatLogs->hasMorePages(),
            'nextPage' => $chatLogs->currentPage() + 1
        ];
    }
}