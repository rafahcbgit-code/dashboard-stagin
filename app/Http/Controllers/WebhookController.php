<?php

namespace App\Http\Controllers;

use Str;
use App\Models\Chat;
use GuzzleHttp\Client;
use App\Models\ChatLog;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\Template;
use App\Models\AutoReply;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use GuzzleHttp\Exception\GuzzleException;
use App\Resolvers\PaymentPlatformResolver;
use GuzzleHttp\Exception\ConnectException;
use Propaganistas\LaravelPhone\PhoneNumber;
use App\Http\Controllers\Controller as BaseController;

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
        
        /* Log::channel('webhook')->info('Incoming webhook payload', [
            'payload' => $request->all()
        ]); */

        $verifyToken = Setting::where('key', 'whatsapp_callback_token')->first()->value;

        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return Response::make($challenge, 200);
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

            /*$appSecret = $metadata->whatsapp->app_secret;
            $headerSignature = $request->header('X-Hub-Signature-256');
            $payload = $request->getContent();
            $calculatedSignature = 'sha256=' . hash_hmac('sha256', $payload, $appSecret);

            if (!$this->isValidSignature($calculatedSignature, $headerSignature)) {
                return $this->invalidSignatureResponse();
            }*/

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
                return Response::make($challenge, 200);
            } else {
                return Response::json(['error' => 'Forbidden'], 404);
            }
        } catch (\Exception $e) {
            Log::error("Error processing webhook: " . $e->getMessage());
            return Response::json(['error' => $e->getMessage()], 403);
        }
    }

    protected function handlePostRequest(Request $request, Organization $organization)
    {
        $res = $request->entry[0]['changes'][0];

        

        if($res['field'] === 'messages'){
            $contacts = $res['value']['contacts'][0] ?? null;
            $messages = $res['value']['messages'] ?? null;
            $statuses = $res['value']['statuses'] ?? null;

            if($statuses) {
                //$response = $res['value']['statuses'][0];
                foreach($statuses as $response){
                    $chatWamId = $response['id'];
                    $status = $response['status'];

                    $chat = Chat::where('wam_id', $chatWamId)
                                ->where('organization_id', $organization->id)
                                ->first();

                    /* Log::channel('webhook')->info('webhook statuses', [
                        'chatWamId' => $chatWamId,
                        'status' => $status,
                        "chat_id" => $chat ? $chat->id : null
                    ]); */
                                
                    if($chat){
                        $chat->status = $status;
                        $chat->save();

                        $chatStatusLog = new ChatStatusLog;
                        $chatStatusLog->chat_id = $chat->id;
                        $chatStatusLog->metadata = json_encode($response);
                        $chatStatusLog->save();
                    }else{
                        MissedChatStatusUpdate::create([
                            'wam_id' => $chatWamId,
                            'organization_id' => $organization->id,
                            'payload' => json_encode($response),
                            'status' => $status,
                            'state' => MissedChatStatusUpdate::STATE_NOT_PROCESSED,
                            'created_at' => now()
                        ]);
                    }
                    // return Response::json(['error' => 'chat not found'], 404);
                }

                // Trigger webhook
                WebhookHelper::triggerWebhookEvent('message.status.update', [
                    'data' => $res,
                ], $organization->id);
            } else if($messages) {
                $isLimitReached = SubscriptionService::isSubscriptionLimitReachedForInboundMessages($organization->id);
                //Log::info($messages);

                if(!$isLimitReached){
                    foreach($messages as $response){
                        $phone = $response['from'];

                        if (substr($phone, 0, 1) !== '+') {
                            $phone = '+' . $phone;
                        }

                        // Use PhoneService for Brazilian number validation and formatting
                        $phone = PhoneService::getE164Format($phone);

                        //Check if contact exists in organization
                        $contact = Contact::where('organization_id', $organization->id)->where('phone', $phone)->whereNull('deleted_at')->first();
                        $isNewContact = false;

                        //call to check the unsunscribe triggre keyword
                        $messageText = null;
                        try {
                            $messageText = $res['value']['messages'][0]['text']['body'];
                        } catch (\Exception $e) {
                            //throw $th;
                        }


                        if(!$contact){
                            //Create a contact
                            $contactData = $res['value']['contacts'][0]['profile'] ?? null;

                            $contact = Contact::create([
                                'first_name' => $contactData['name'] ?? null,
                                'last_name' => null,
                                'email' => null,
                                'phone' => $phone,
                                'organization_id' => $organization->id,
                                'created_by' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            $isNewContact = true;
                        }

                        if($contact){
                            if (empty($contact->first_name)) {
                                $contactData = $res['value']['contacts'][0]['profile'] ?? null;

                                if ($contactData && isset($contactData['name'])) {
                                    $contact->update([
                                        'first_name' => $contactData['name'],
                                    ]);
                                } else {
                                    Log::channel('webhook')->info('Missing contact profile data', [
                                        'payload' => $res,
                                    ]);
                                }
                            }
  
                            //call check to comapre between message and triggers
                            //The addition was made here to ensure that the contact exists
                            if($messageText) {
                                $this->checker->check($organization, $messageText, $contact->id);
                            }

                            $chat = Chat::where('wam_id', $response['id'])->where('organization_id', $organization->id)->first();

                            if(!$chat){
                                //First open the chat
                                (new ChatService($organization->id))->handleTicketAssignment($contact->id);

                                $chat = new Chat;
                                $chat->organization_id = $organization->id;
                                $chat->wam_id = $response['id'];
                                $chat->contact_id = $contact->id;
                                $chat->type = 'inbound';
                                $chat->metadata = json_encode($response);
                                $chat->status = 'delivered';
                                $chat->save();
                            
                                if($chat){
                                    if($response['type'] === 'image' || $response['type'] === 'video' || $response['type'] === 'audio' || $response['type'] === 'document' || $response['type'] === 'sticker'){
                                        $type = $response['type'];
                                        $mediaId = $response[$type]['id'];

                                        //Get & Download media file
                                        $media = $this->getMedia($mediaId, $organization);
                                        $downloadedFile = $this->downloadMedia($media, $organization);

                                        //Upload media
                                        $chatMedia = new ChatMedia;
                                        $chatMedia->name = $type === 'document' ? $response[$type]['filename'] : 'N/A';
                                        $chatMedia->path = $downloadedFile['media_url'];
                                        $chatMedia->type = $media['mime_type'];
                                        $chatMedia->size = $media['file_size'];
                                        $chatMedia->location = $downloadedFile['location'];
                                        $chatMedia->created_at = now();
                                        $chatMedia->save();

                                        //Update chat
                                        Chat::where('id', $chat->id)->update([
                                            'media_id' => $chatMedia->id
                                        ]);
                                    }
                                }

                                $chat = Chat::with('contact','media')->where('id', $chat->id)->first();

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

                                event(new NewChatEvent($chatArray, $organization->id));

                                $isMessageLimitReached = SubscriptionService::isSubscriptionFeatureLimitReached($organization->id, 'message_limit');

                                if(!$isMessageLimitReached && $contact->bot_enable){
                                    if($response['type'] === 'text' || $response['type'] === 'button'|| $response['type'] === 'audio'|| $response['type'] === 'interactive'){
                                        (new AutoReplyService)->checkAutoReply($chat, $isNewContact);
                                    }
                                }
                            }
                        }
                    }

                    // Trigger webhook
                    WebhookHelper::triggerWebhookEvent('message.received', [
                        'data' => $res,
                    ], $organization->id);
                }
            }
        } 
        // NEW: Handle SMB Message Echoes for Coexistence Mode
        else if($res['field'] === 'smb_message_echoes'){
                $messageEchoes = $res['value']['message_echoes'] ?? null;
                
                // Log::info('SMB Message Echoes received', ['echoes' => $messageEchoes, 'organization_id' => $organization->id]);
                
                if($messageEchoes) {
                    foreach($messageEchoes as $echo) {
                        try {
                            $recipientPhone = $echo['to'] ?? null; // The recipient phone number
                            $senderPhone = $echo['from'] ?? null; // Business phone number
                            $messageId = $echo['id'] ?? null;
                            $timestamp = $echo['timestamp'] ?? null;
                            $messageType = $echo['type'] ?? null;
                            
                            if(!$recipientPhone) {
                                Log::warning('No recipient phone in SMB echo', ['echo' => $echo]);
                                continue;
                            }
                            
                            // Format recipient phone number
                            if (substr($recipientPhone, 0, 1) !== '+') {
                                $recipientPhone = '+' . $recipientPhone;
                            }
                            $recipientPhone = PhoneService::getE164Format($recipientPhone);
                            
                            // Check if contact exists, create if not
                            $contact = Contact::where('organization_id', $organization->id)
                                ->where('phone', $recipientPhone)
                                ->whereNull('deleted_at')
                                ->first();
                                
                            $isNewContact = false;
                            if(!$contact) {
                                $contact = Contact::create([
                                    'first_name' => $recipientPhone, // Will be updated when they respond
                                    'last_name' => null,
                                    'email' => null,
                                    'phone' => $recipientPhone,
                                    'organization_id' => $organization->id,
                                    'created_by' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                $isNewContact = true;
                                
                                // Handle ticket assignment for new contact
                                (new ChatService($organization->id))->handleTicketAssignment($contact->id);
                            }
                            
                            // Check if this echo message already exists
                            $existingChat = Chat::where('wam_id', $messageId)
                                ->where('organization_id', $organization->id)
                                ->first();
                                
                            if(!$existingChat && $messageId) {
                                // Create chat record for the echoed message (outbound)
                                $chat = new Chat;
                                $chat->organization_id = $organization->id;
                                $chat->wam_id = $messageId;
                                $chat->contact_id = $contact->id;
                                $chat->type = 'outbound'; // This is an outbound message from Business App
                                $chat->metadata = json_encode($echo);
                                $chat->status = 'sent'; // Message was sent via Business App
                                $chat->created_at = $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now();
                                $chat->save();
                                
                                // Handle media if present
                                if(in_array($messageType, ['image', 'video', 'audio', 'document', 'sticker']) && isset($echo[$messageType]['id'])) {
                                    $mediaId = $echo[$messageType]['id'];
                                    
                                    try {
                                        $media = $this->getMedia($mediaId, $organization);
                                        $downloadedFile = $this->downloadMedia($media, $organization);
                                        
                                        $chatMedia = new ChatMedia;
                                        $chatMedia->name = $messageType === 'document' ? ($echo[$messageType]['filename'] ?? 'N/A') : 'N/A';
                                        $chatMedia->path = $downloadedFile['media_url'];
                                        $chatMedia->type = $media['mime_type'];
                                        $chatMedia->size = $media['file_size'];
                                        $chatMedia->location = $downloadedFile['location'];
                                        $chatMedia->created_at = now();
                                        $chatMedia->save();
                                        
                                        $chat->media_id = $chatMedia->id;
                                        $chat->save();
                                    } catch(\Exception $mediaError) {
                                        Log::error('Failed to process media for SMB echo', [
                                            'error' => $mediaError->getMessage(),
                                            'media_id' => $mediaId,
                                            'chat_id' => $chat->id
                                        ]);
                                    }
                                }
                                
                                // Create chat log entry
                                $chatlogId = ChatLog::insertGetId([
                                    'contact_id' => $contact->id,
                                    'entity_type' => 'chat',
                                    'entity_id' => $chat->id,
                                    'created_at' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now()
                                ]);
                                
                                // Broadcast event for real-time updates
                                $chatLogArray = ChatLog::where('id', $chatlogId)->where('deleted_at', null)->first();
                                $chatArray = array([
                                    'type' => 'chat',
                                    'value' => $chatLogArray->relatedEntities
                                ]);
                                
                                event(new NewChatEvent($chatArray, $organization->id));
                                
                                
                            }
                            
                        } catch(\Exception $e) {
                            Log::error('Error processing SMB message echo', [
                                'error' => $e->getMessage(),
                                'echo' => $echo,
                                'organization_id' => $organization->id
                            ]);
                        }
                    }
                    
                    // Trigger webhook for SMB echoes
                    WebhookHelper::triggerWebhookEvent('smb.message.echo', [
                        'data' => $res,
                    ], $organization->id);
                }
        }
        // NEW: Handle SMB App State Sync for Coexistence Mode
        else if($res['field'] === 'smb_app_state_sync'){
            $stateSync = $res['value']['state_sync'] ?? null;

            try {
                $decoded = json_decode($organization->metadata, true);
                if($decoded['sync_contacts'] == 0){
                    return Response::json(['status' => 'success'], 200);
                }

            } catch (\Exception $e) {
            
            }
            
            if($stateSync && is_array($stateSync)) {
                foreach($stateSync as $syncItem) {
                    try {
                        $type = $syncItem['type'] ?? null;
                        $action = $syncItem['action'] ?? null;
                        $timestamp = $syncItem['metadata']['timestamp'] ?? null;
                        
                        if($type === 'contact') {
                            $contactInfo = $syncItem['contact'] ?? null;
                            if(!$contactInfo) continue;
                            
                            $phone = $contactInfo['phone_number'] ?? null;
                            $fullName = $contactInfo['full_name'] ?? null;
                            $firstName = $contactInfo['first_name'] ?? null;
                            
                            if(!$phone) continue;
                            
                            // Format phone number
                            if (substr($phone, 0, 1) !== '+') {
                                $phone = '+' . $phone;
                            }
                            $phone = PhoneService::getE164Format($phone);
                            
                            // Check if contact exists
                            $contact = Contact::where('organization_id', $organization->id)
                                ->where('phone', $phone)
                                ->whereNull('deleted_at')
                                ->first();
                            
                            if($action === 'create' || $action === 'update') {
                                if(!$contact) {
                                    // Create new contact from state sync
                                    $contact = Contact::create([
                                        'first_name' => $firstName ?: $fullName,
                                        'last_name' => null,
                                        'email' => null,
                                        'phone' => $phone,
                                        'organization_id' => $organization->id,
                                        'created_by' => 0,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                    
                                    // Handle ticket assignment
                                    (new ChatService($organization->id))->handleTicketAssignment($contact->id);
                                    
                                } else if($action === 'update') {
                                    // Update existing contact if name is missing or different
                                    $updateData = [];
                                    if(empty($contact->first_name) && !empty($firstName)) {
                                        $updateData['first_name'] = $firstName;
                                    } elseif(empty($contact->first_name) && !empty($fullName)) {
                                        $updateData['first_name'] = $fullName;
                                    }
                                    
                                    if(!empty($updateData)) {
                                        $contact->update($updateData);
                                    }
                                }
                            } elseif($action === 'delete' && $contact) {
                                // Soft delete contact
                                $contact->deleted_at = now();
                                $contact->save();
                            }
                        }
                        
                    } catch(\Exception $e) {
                        Log::error('Error processing SMB state sync item', [
                            'error' => $e->getMessage(),
                            'sync_item' => $syncItem,
                            'organization_id' => $organization->id
                        ]);
                    }
                }
                
                // Trigger webhook for state sync
                WebhookHelper::triggerWebhookEvent('smb.app.state.sync', [
                    'data' => $res,
                ], $organization->id);
            }
        }
        // NEW: Handle History Webhook for Coexistence Mode
        else if($res['field'] === 'history'){

            try {
                $decoded = json_decode($organization->metadata, true);
                if($decoded['sync_history'] == 0){
                    return Response::json(['status' => 'success'], 200);
                }

            } catch (\Exception $e) {
            
            }

            $history = $res['value']['history'] ?? null;
            
            
            if($history && is_array($history)) {
                foreach($history as $historyChunk) {
                    $threads = $historyChunk['threads'] ?? [];
                    $metadata = $historyChunk['metadata'] ?? [];
                    $phase = $metadata['phase'] ?? null;
                    $progress = $metadata['progress'] ?? null;
                    
                    foreach($threads as $thread) {
                        try {
                            $threadId = $thread['id'] ?? null; // WhatsApp user phone number
                            $messages = $thread['messages'] ?? [];
                            
                            if(!$threadId) continue;
                            
                            // Format phone number
                            $phone = $threadId;
                            if (substr($phone, 0, 1) !== '+') {
                                $phone = '+' . $phone;
                            }
                            $phone = PhoneService::getE164Format($phone);
                            
                            // Create contact if doesn't exist
                            $contact = Contact::where('organization_id', $organization->id)
                                ->where('phone', $phone)
                                ->whereNull('deleted_at')
                                ->first();
                                
                            if(!$contact) {
                                $contact = Contact::create([
                                    'first_name' => null,
                                    'last_name' => null,
                                    'email' => null,
                                    'phone' => $phone,
                                    'organization_id' => $organization->id,
                                    'created_by' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                                
                                (new ChatService($organization->id))->handleTicketAssignment($contact->id);
                            }
                            
                            // Process messages in thread
                            foreach($messages as $message) {
                                $messageId = $message['id'] ?? null;
                                $from = $message['from'] ?? null;
                                $to = $message['to'] ?? null;
                                $timestamp = $message['timestamp'] ?? null;
                                $type = $message['type'] ?? null;
                                $historyContext = $message['history_context'] ?? [];
                                $status = $historyContext['status'] ?? 'delivered';
                                
                                if(!$messageId) continue;
                                
                                // Check if message already exists
                                $existingChat = Chat::where('wam_id', $messageId)
                                    ->where('organization_id', $organization->id)
                                    ->first();
                                    
                                if(!$existingChat) {
                                    // Determine if inbound or outbound
                                    $isOutbound = ($from !== $phone); // If from is not the contact, it's outbound
                                    
                                    $chat = new Chat;
                                    $chat->organization_id = $organization->id;
                                    $chat->wam_id = $messageId;
                                    $chat->contact_id = $contact->id;
                                    $chat->type = $isOutbound ? 'outbound' : 'inbound';
                                    $chat->metadata = json_encode($message);
                                    $chat->status = $status;
                                    $chat->created_at = $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now();
                                    $chat->save();
                                    
                                    // Create chat log
                                    ChatLog::create([
                                        'contact_id' => $contact->id,
                                        'entity_type' => 'chat',
                                        'entity_id' => $chat->id,
                                        'created_at' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now()
                                    ]);
                                }
                            }
                            
                        } catch(\Exception $e) {
                            Log::error('Error processing history thread', [
                                'error' => $e->getMessage(),
                                'thread_id' => $threadId ?? 'unknown',
                                'organization_id' => $organization->id
                            ]);
                        }
                    }
                }
                
                // Trigger webhook for history
                WebhookHelper::triggerWebhookEvent('smb.history.received', [
                    'data' => $res,
                ], $organization->id);
            }
        }
        else if($res['field'] === 'message_template_status_update'){
            $response = $res['value'] ?? null;
            $template = Template::where('meta_id', $response['message_template_id'])->first();

            if($template){
                $template->status = $response['event'];
                $template->save();
            }
        } 
        else if($res['field'] === 'account_review_update'){
            //Account Status
            $response = $res['value'] ?? null;
            $organizationConfig = Organization::where('id', $organization->id)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['whatsapp']['account_review_status'] = $response['decision'] ?? NULL;

            $updatedMetadataJson = json_encode($metadataArray);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();
        } 
        else if($res['field'] === 'phone_number_name_update'){
            //Display Name
            $response = $res['value'] ?? null;

            if($response['decision'] == 'APPROVED'){
                $organizationConfig = Organization::where('id', $organization->id)->first();
                $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

                $metadataArray['whatsapp']['verified_name'] = $response['requested_verified_name'] ?? NULL;

                $updatedMetadataJson = json_encode($metadataArray);
                $organizationConfig->metadata = $updatedMetadataJson;
                $organizationConfig->save();
            }
        } 
        else if($res['field'] === 'phone_number_quality_update'){
            //messaging_tier_limit
            $response = $res['value'] ?? null;
            $organizationConfig = Organization::where('id', $organization->id)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['whatsapp']['messaging_limit_tier'] = $response['current_limit'] ?? NULL;

            $updatedMetadataJson = json_encode($metadataArray);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();
        } 
        else if($res['field'] === 'business_capability_update'){
            //messaging_tier_limit
            $response = $res['value'] ?? null;
            $organizationConfig = Organization::where('id', $organization->id)->first();
            $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $metadataArray['whatsapp']['max_daily_conversation_per_phone'] = $response['max_daily_conversation_per_phone'] ?? NULL;
            $metadataArray['whatsapp']['max_phone_numbers_per_business'] = $response['max_phone_numbers_per_business'] ?? NULL;

            $updatedMetadataJson = json_encode($metadataArray);
            $organizationConfig->metadata = $updatedMetadataJson;
            $organizationConfig->save();
        }

        return Response::json(['status' => 'success'], 200);
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