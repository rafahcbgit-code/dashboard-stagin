<?php

namespace App\Services;

use App\Helpers\WebhookHelper;
use App\Http\Resources\AutoReplyResource;
use App\Models\AutoReply;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\MediaService;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Validator;
use App\Models\ChatSystemMessage;
use App\Services\WorkingHoursService;
class AutoReplyService
{
    protected $workingHoursService;
    
    public function __construct(){
        $this->workingHoursService = new WorkingHoursService();
    }
    
    public function getRows(object $request)
    {
        $organizationId = session()->get('current_organization');
        $model = new AutoReply;
        $searchTerm = $request->query('search');

        return AutoReplyResource::collection($model->listAll($organizationId, $searchTerm));
    }

    public function store(object $request, $uuid = null)
    {
        $model = $uuid == null ? new AutoReply : AutoReply::where('uuid', $uuid)->first();
        $model['name'] = $request->name;
        $model['trigger'] = $request->trigger;
        $model['match_criteria'] = $request->match_criteria;

        $metadata['type'] = $request->response_type;
        if($request->response_type === 'image' || $request->response_type === 'audio'){
            if($request->hasFile('response')){
                $storage = Setting::where('key', 'storage_system')->first()->value;
                $fileName = $request->file('response')->getClientOriginalName();
                $fileContent = $request->file('response');

                if($storage === 'local'){
                    $file = Storage::disk('local')->put('public', $fileContent);
                    $mediaFilePath = $file;
                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                } else if($storage === 'aws') {
                    $filePath = 'uploads/media/received'  . session()->get('current_organization') . '/' . $fileName;
                    $file = Storage::disk('s3')->put($filePath, $fileContent, 'public');
                    $mediaFilePath = Storage::disk('s3')->url($filePath);
                    $mediaUrl = $mediaFilePath;
                }

                $uploadedMedia = MediaService::upload($request->file('response'));

                $metadata['data']['file']['name'] = $fileName;
                $metadata['data']['file']['location'] = $mediaFilePath;
                $metadata['data']['file']['url'] = $mediaUrl;
            } else {
                $media = json_decode($model->metadata)->data;
                $metadata['data']['file']['name'] = $media->file->name;
                $metadata['data']['file']['location'] = $media->file->location;
                $metadata['data']['file']['url'] = $media->file->url;
            }
        } else if($request->response_type === 'text') {
            $metadata['data']['text'] = $request->response;
        } else {
            $metadata['data']['template'] = $request->response;
        }

        $model['metadata'] = json_encode($metadata);
        $model['updated_at'] = now();

        if($uuid === null){
            $model['organization_id'] = session()->get('current_organization');
            $model['created_by'] = auth()->user()->id;
            $model['created_at'] = now();
        }

        $model->save();

        // Prepare a clean contact object for webhook
        $cleanModel = $model->makeHidden(['id', 'organization_id', 'created_by']);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent($uuid === null ? 'autoreply.created' : 'autoreply.updated', $cleanModel);
    }

    public function destroy($uuid)
    {
        AutoReply::where('uuid', $uuid)->update([
            'deleted_by' => auth()->user()->id,
            'deleted_at' => now()
        ]);

        // Trigger webhook
        WebhookHelper::triggerWebhookEvent('autoreply.deleted', [
            'list' => [
                'uuid' => $uuid,
                'deleted_at' => now()->toISOString()
            ],
        ]);
    }

    public function checkAutoReply(Chat $chat, $isNewContact)
    {Log::warning(' inside checkAutoReply');
        $organizationId = $chat->organization_id;

        $this->replySequence($organizationId, $chat, $isNewContact);
    }

    private function replySequence($organizationId, $chat, $isNewContact)
    {
        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $activeFlow = false;
        $modulePath = base_path('modules/FlowBuilder');
        
        if (File::exists($modulePath)) {
            if (class_exists(\Modules\FlowBuilder\Services\FlowExecutionService::class)) {
                $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
                $activeFlow = $query->hasActiveFlow($chat);
            }
        }

        // Override response sequence if there is an active flow
        if ($activeFlow) {
            $response_sequence = ['Automated Flows'];
        } else {
            // Use the response sequence from metadata or fallback to default
            $response_sequence = $metadataArray['automation']['response_sequence'] ?? ['Basic Replies', 'Automated Flows', 'AI Reply Assistant'];
        }

        // Define mapping of sequence items to functions
        $sequenceFunctions = [
            'Basic Replies' => function() use ($chat) {
                return $this->handleBasicReplies($chat);
            },
            'Automated Flows' => function() use ($organizationId, $chat, $isNewContact) {
                return $this->handleAutomatedFlows($organizationId, $chat, $isNewContact);
            },
            'AI Reply Assistant' => function() use ($chat) {
                return $this->handleAIReplyAssistant($chat);
            },
        ];

        // Initialize a variable to hold the response (or handle chaining, etc.)
        $response = null;

        // Iterate through the sequence, applying each function in order
        foreach ($response_sequence as $sequenceItem) {
            if (isset($sequenceFunctions[$sequenceItem])) {
                $response = $sequenceFunctions[$sequenceItem]();

                // \Log::info($sequenceItem);
                // \Log::info($response);

                if ($response) {
                    // If a response is found, exit the loop
                    break;
                }
            }
        }

        return $response;
    }

    private function handleBasicReplies($chat)
    {
        $organizationId = $chat->organization_id;
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }
        
        $receivedMessage = " " . $text;


        //Check basic reply flow
        $autoReplies = AutoReply::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->get();

        foreach ($autoReplies as $autoReply) {
            $triggerValues = $this->getTriggerValues($autoReply->trigger);

            foreach ($triggerValues as $trigger) {
                if ($this->checkMatch($receivedMessage, $trigger, $autoReply->match_criteria)) {
                    $this->sendReply($chat, $autoReply);
                    return true;
                }
            }
        }
        $isWithinWorkingHours = $this->workingHoursService->isWithinWorkingHours($organizationId);

        // NEW: Check for global reply if no auto-reply matched
        if ($this->sendGlobalReply($chat) && $isWithinWorkingHours) {
            return true;
        }

        return false; // No reply was sent
    }

    private function handleAIReplyAssistant($chat)
    {
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }
        
        $receivedMessage = " " . $text;

        if (file_exists(base_path('modules/IntelliReply/Services/AIResponseService.php'))) {
            $query = new \Modules\IntelliReply\Services\AIResponseService();
            if ($query->handleAIResponse($chat, $receivedMessage)) {
                return true;
            }
        }

        return false; // No reply was sent
    }

    private function handleAutomatedFlows($organizationId, $chat, $isNewContact)
    {
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if($metadata['type'] == 'text'){
            $text = $metadata['text']['body'];
        } else if(json_decode($chat->metadata)->type == 'button'){
            $text = $metadata['button']['payload'];
        } else if(json_decode($chat->metadata)->type == 'interactive'){
            if($metadata['interactive']['type'] == 'button_reply'){
                $text = $metadata['interactive']['button_reply']['title'];
            } else if($metadata['interactive']['type'] == 'list_reply'){
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }

        $receivedMessage = " " . $text;

        if (file_exists(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'))) {
            $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId);
            return $query->executeFlow($chat, $isNewContact, $receivedMessage);
        }
    }

    private function getTriggerValues($trigger)
    {
        return is_string($trigger) && strpos($trigger, ',') !== false
            ? explode(',', $trigger)
            : (array) $trigger;
    }

    private function checkMatch($receivedMessage, $trigger, $criteria)
    {
        $normalizedTrigger = trim($trigger);

        if ($criteria === 'exact match') {
            // Check if the text contains Arabic characters
            $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
            
            if ($hasArabic) {
                // For Arabic text, use exact case-sensitive matching
                return $receivedMessage === " " . $normalizedTrigger;
            } else {
                // For English text, use case-insensitive matching
                return strtolower($receivedMessage) === " " . strtolower($normalizedTrigger);
            }
        } else if ($criteria === 'contains') {
            $triggerWords = explode(' ', $normalizedTrigger);
            
            // Check if the text contains Arabic characters
            $hasArabic = preg_match('/[\p{Arabic}]/u', $receivedMessage . $normalizedTrigger);
            
            if ($hasArabic) {
                // For Arabic text, use simple string matching without word boundaries
                foreach ($triggerWords as $word) {
                    if (strpos($receivedMessage, $word) !== false) {
                        return true;
                    }
                }
                return false;
            } else {
                // For non-Arabic text, use case-insensitive regex approach
                $pattern = '/\b(' . implode('|', array_map('preg_quote', $triggerWords)) . ')\b/i';
                return preg_match($pattern, strtolower($receivedMessage)) === 1;
            }
        }
    
        return false;
    }

    protected function sendReply(Chat $chat, AutoReply $autoreply)
    {
        $contact = Contact::where('id', $chat->contact_id)->first();
        $organization_id = $chat->organization_id;
        $metadata = json_decode($autoreply->metadata);
        $replyType = $metadata->type;

        if($replyType === 'text'){
            $message = $this->replacePlaceholders($organization_id, $contact->uuid, $metadata->data->text);
            $this->initializeWhatsappService($organization_id)->sendMessage($contact->uuid, $message);
        } else if($replyType === 'audio' || $replyType === 'image'){
            $location = strpos($metadata->data->file->location, 'public\\') === 0 ? 'local' : 'amazon';
            $this->initializeWhatsappService($organization_id)->sendMedia($contact->uuid, $replyType, $metadata->data->file->name, $metadata->data->file->location, $metadata->data->file->url, $location);
        }
    }

    private function initializeWhatsappService($organizationId)
    {
        $config = Organization::where('id', $organizationId)->first()->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        return new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $organizationId);
    }

    private function replacePlaceholders($organizationId, $contactUuid, $message){
        $organization = Organization::where('id', $organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();
        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        $full_address = ($address['street'] ?? Null) . ', ' .
                        ($address['city'] ?? Null) . ', ' .
                        ($address['state'] ?? Null) . ', ' .
                        ($address['zip'] ?? Null) . ', ' .
                        ($address['country'] ?? Null);

        $data = [
            'first_name' => $contact->first_name ?? Null,
            'last_name' => $contact->last_name ?? Null,
            'full_name' => $contact->full_name ?? Null,
            'email' => $contact->email ?? Null,
            'phone' => $contact->phone ?? Null,
            'organization_name' => $organization->name,
            'full_address' => $full_address,
            'street' => $address['street'] ?? Null,
            'city' => $address['city'] ?? Null,
            'state' => $address['state'] ?? Null,
            'zip_code' => $address['zip'] ?? Null,
            'country' => $address['country'] ?? Null,
        ];

        $transformedMetadata = [];
        if($metadata){
            foreach ($metadata as $key => $value) {
                $transformedKey = strtolower(str_replace(' ', '_', $key));
                $transformedMetadata[$transformedKey] = $value;
            }
        }

        $mergedData = array_merge($data, $transformedMetadata);

        //Log::info($mergedData);

        // First handle URL-encoded placeholders with {url:placeholder} syntax
        $message = preg_replace_callback('/\{url:(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            if (isset($mergedData[$key])) {
                return rawurlencode($mergedData[$key]);
            }
            return $matches[0];
        }, $message);
        
        // Then handle regular placeholders
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            return isset($mergedData[$key]) ? $mergedData[$key] : $matches[0];
        }, $message);
    }

    private function sendGlobalReply($chat)
    {
        $organizationId = $chat->organization_id;
        
        // Get organization settings
        $organization = Organization::where('id', $organizationId)->first();
        if (!$organization || !$organization->metadata) {
            return false;
        }
        
        $metadata = json_decode($organization->metadata, true);
        
        // Check if global reply is active
        if (!isset($metadata['global_reply']) || 
            !$metadata['global_reply']['active'] || 
            empty($metadata['global_reply']['message'])) {
            return false;
        }
        
        $globalReply = $metadata['global_reply'];
        $contact = Contact::where('id', $chat->contact_id)->first();
        
        if (!$contact) {
            return false;
        }
        
        // Send interactive message with buttons if buttons exist
        if (!empty($globalReply['buttons'])) {
            $buttons = [];
            foreach ($globalReply['buttons'] as $button) {
                $buttons[] = [
                    'id' => $button['payload'],
                    'title' => $button['title']
                ];
            }
            
            $this->initializeWhatsappService($organizationId)->sendMessage(
                $contact->uuid, 
                $globalReply['message'], 
                null, 
                'interactive buttons', 
                $buttons
            );
        } else {
            // Send simple text message
            $this->initializeWhatsappService($organizationId)->sendMessage(
                $contact->uuid, 
                $globalReply['message']
            );
        }
        
        return true;
    }

    private function alreadySentOutsideWorkingHours(Chat $chat): bool
{
    try {
        // Get the last system message of type "outside_working_hours" for this contact
        $last = ChatSystemMessage::where('contact_id', $chat->contact_id)
            ->where('type', 'outside_working_hours')
            ->orderByDesc('sent_at')
            ->first();

        // If no previous message exists, it was not sent before
        if (! $last) {
            return false;
        }

        // Return true only if:
        // 1. The message was sent today
        // 2. We are still outside working hours
        return $last->sent_at->isToday()
            && ! $this->workingHoursService
                ->isWithinWorkingHours($chat->organization_id);

    } catch (\Throwable $e) {
        Log::warning('alreadySentOutsideWorkingHours check failed', [
            'error' => $e->getMessage(),
        ]);

        return false;
    }
}


public function sendOutsideWorkingHours($chat)
{
    try {
        // Do not send anything if we are currently within working hours
        if ($this->workingHoursService->isWithinWorkingHours($chat->organization_id)) {
            Log::warning('sendOutsideWorkingHours skipped - within working hours', [
                'organization_id' => $chat->organization_id,
            ]);
            return false;
        }

        // Do not resend the outside working hours message
        // if it was already sent during the current outside-hours period
        if ($this->alreadySentOutsideWorkingHours($chat)) {
            Log::warning('sendOutsideWorkingHours skipped - already sent', [
                'contact_id' => $chat->contact_id,
            ]);
            return false;
        }

        // Load organization and its metadata
        $organization = Organization::find($chat->organization_id);
        if (! $organization || ! $organization->metadata) {
            Log::warning('sendOutsideWorkingHours aborted - organization or metadata missing', [
                'organization_id' => $chat->organization_id,
            ]);
            return false;
        }

        $metadata = json_decode($organization->metadata, true);

        // Validate working hour auto-reply configuration
        if (
            ! isset($metadata['working_hour_reply']) ||
            ! ($metadata['working_hour_reply']['active'] ?? false) ||
            empty($metadata['working_hour_reply']['message'])
        ) {
            Log::warning('sendOutsideWorkingHours aborted - working_hour_reply not configured or inactive', [
                'organization_id' => $chat->organization_id,
            ]);
            return false;
        }

        $reply = $metadata['working_hour_reply'];

        // Load contact
        $contact = Contact::find($chat->contact_id);
        if (! $contact) {
            Log::warning('sendOutsideWorkingHours aborted - contact not found', [
                'contact_id' => $chat->contact_id,
            ]);
            return false;
        }

        // Send WhatsApp message (interactive buttons or plain text)
        if (! empty($reply['buttons'])) {
            $buttons = [];

            foreach ($reply['buttons'] as $button) {
                $buttons[] = [
                    'id'    => $button['payload'],
                    'title' => $button['title'],
                ];
            }

            $this->initializeWhatsappService($chat->organization_id)
                ->sendMessage(
                    $contact->uuid,
                    $reply['message'],
                    null,
                    'interactive buttons',
                    $buttons
                );
        } else {
            $this->initializeWhatsappService($chat->organization_id)
                ->sendMessage(
                    $contact->uuid,
                    $reply['message']
                );
        }

        // Persist system message to prevent duplicate sends
        try {
            Log::channel('webhook')->info('Creating ChatSystemMessage (outside_working_hours)', [
                'contact_id' => $chat->contact_id,
            ]);

            $created = ChatSystemMessage::create([
                'contact_id' => $chat->contact_id,
                'type'       => 'outside_working_hours',
                'sent_at'    => now(),
            ]);

            Log::channel('webhook')->info('ChatSystemMessage created successfully', [
                'id'         => $created->id ?? null,
                'contact_id' => $created->contact_id ?? null,
                'sent_at'    => $created->sent_at ?? null,
            ]);
        } catch (\Throwable $e) {
            // Failing to save the system message should NOT block the user reply
            Log::channel('webhook')->error('Failed to create ChatSystemMessage (outside_working_hours)', [
                'error'      => $e->getMessage(),
                'contact_id'=> $chat->contact_id,
            ]);
        }

        return true;

    } catch (\Throwable $e) {
        // Catch any unexpected exception to avoid breaking the message flow
        Log::channel('webhook')->error('sendOutsideWorkingHours failed unexpectedly', [
            'chat_id'         => $chat->id ?? null,
            'contact_id'      => $chat->contact_id ?? null,
            'organization_id' => $chat->organization_id ?? null,
            'error'           => $e->getMessage(),
        ]);

        return false;
    }
}
}