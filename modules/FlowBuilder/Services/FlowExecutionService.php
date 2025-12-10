<?php

namespace Modules\FlowBuilder\Services;

use App\Helpers\CustomHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\ActionExecutionService;

class FlowExecutionService
{
    private $whatsappService;
    private $organizationId;

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
    
    /**
     * Execute a flow for a user based on their input.
     *
     * @param $chat
     * @param boolean $isNewContact
     * @param string $message
     * @return FlowStep|null
     */

    public function executeFlow($chat, $isNewContact, $message)
{ 
    if(CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)){
        
        $flowData = FlowUserData::where('contact_id', $chat->contact_id)->first();
        $flowId = null;

        if($flowData && $flowData->exists){
            $flow = Flow::find($flowData->flow_id);
            
            if(!$flow){
                Log::warning("DELETION POINT 1: Flow not found, deleting FlowUserData for contact {$chat->contact_id}");
                FlowUserData::where('contact_id', $chat->contact_id)->delete();
                $flowData = null;
            } else {
                $flowId = $flowData->flow_id;
                $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, strtolower(trim($message)));
                
                if ($result === 'validation_failed' || $result === 'delayed') {
                    return false; 
                }
                
                return $result;
            }
        }

        if(!$flowData){
            $msg = strtolower(trim($message)); 
            $flowQuery = Flow::where('organization_id', $chat->organization_id)->where('status', 'active');
            $flow = null;

            if($isNewContact){
                $flow = $flowQuery->where('trigger', 'new_contact')->first();
            } 
            
            if(!$flow){        
                Log::warning("inside exact_match");
                
           
                $flow = (clone $flowQuery)
                    ->where('trigger', 'exact_match')
                    ->where('keywords', $msg) 
                    ->first();

                    Log::warning("Exact match flow search for message '{$msg}': " . ($flow ? "found flow ID {$flow->id}" : "no match found"));
                
            }
            if (!$flow) {

                Log::warning("inside keywords");
                $words = explode(' ', $msg); 
                $conditions = [];
                $bindings = [];

                $conditions[] = "FIND_IN_SET(?, keywords)";
                $bindings[] = $msg; 

                foreach ($words as $word) {
                    $word = strtolower(trim($word));
                    $conditions[] = "FIND_IN_SET(?, keywords)";
                    $bindings[] = $word;
                }

                $flow = DB::table('flows')->whereRaw(
                    '( `trigger` = ? AND organization_id = ?) AND (' . implode(' OR ', $conditions) . ')',
                    array_merge(['keywords', $chat->organization_id], $bindings)
                )->first();
            }

            if ($flow) {
                $flowId = $flow->id;
            }

            if ($flowId) {
                $flowData = new FlowUserData();
                $flowData->fill([
                    'contact_id' => $chat->contact_id,
                    'flow_id' => $flowId,
                    'current_step' => 1
                ])->save();
                
                $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, $msg);
                
                if ($result === 'validation_failed' || $result === 'delayed') {
                    return false; 
                }
                
                return $result;
            }
        }

        return false;
    }
}
    /*public function executeFlow($chat, $isNewContact, $message)
    {
        if(CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)){
            // Find the current step for the user in the flow
            $flowData = FlowUserData::where('contact_id', $chat->contact_id)->first();
            $flowId = null;

            if($flowData && $flowData->exists){
                // Check if the flow still exists in the database
                $flow = Flow::find($flowData->flow_id);
                
                if(!$flow){
                    // Flow has been deleted, remove FlowUserData and proceed as if it doesn't exist
                    Log::warning("DELETION POINT 1: Flow not found, deleting FlowUserData for contact {$chat->contact_id}");
                    FlowUserData::where('contact_id', $chat->contact_id)->delete();
                    $flowData = null;
                } else {
                    // Flow exists, proceed to process flow
                    $flowId = $flowData->flow_id;
                    $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, strtolower(trim($message)));
                    
                    // If validation failed, don't delete flow data
                    if ($result === 'validation_failed') {
                        return false; // Return false but don't delete flow data
                    }
                    
                    // If flow is delayed, don't delete flow data
                    if ($result === 'delayed') {
                        return false; // Return false but don't delete flow data
                    }
                    
                    return $result;
                }
            }

            // If flowData doesn't exist or was deleted, proceed with flow determination logic
            if(!$flowData){
                // Determine the flow based on trigger type
                $flowQuery = Flow::where('organization_id', $chat->organization_id)->where('status', 'active');
                $flow = null;

                //Check if any flow trigger has been hit
                if($isNewContact){
                    $flow = $flowQuery->where('trigger', 'new_contact')->first();
                } else {
                    $msg = strtolower(trim($message)); // Normalize the message
                    $words = explode(' ', $msg); // Split message into individual words

                    $conditions = [];
                    $bindings = [];

                    // Condition to match the full message (as a sentence or phrase)
                    $conditions[] = "FIND_IN_SET(?, keywords)";
                    $bindings[] = $msg; // Add the full message (spaces stripped, like in DB)

                    // Add individual word checks
                    foreach ($words as $word) {
                        $word = strtolower(trim($word));
                        $conditions[] = "FIND_IN_SET(?, keywords)";
                        $bindings[] = $word;
                    }

                    // Final query
                    $flow = \DB::table('flows')->whereRaw(
                        '( `trigger` = ? AND organization_id = ?) AND (' . implode(' OR ', $conditions) . ')',
                        array_merge(['keywords', $chat->organization_id], $bindings)
                    )->first();

                    //Log::info(json_encode($flow));
                }

                // Set the flow ID if a matching flow is found
                if ($flow) {
                    $flowId = $flow->id;
                }

                // If a flow ID was found, create a new FlowUserData record
                if ($flowId) {
                    $flowData = new FlowUserData();
                    $flowData->fill([
                        'contact_id' => $chat->contact_id,
                        'flow_id' => $flowId,
                        'current_step' => 1
                    ])->save();
                    
                    $result = $this->processFlow($chat, $isNewContact, $flowData, $chat->contact_id, strtolower(trim($message)));
                    
                    // If validation failed, don't delete flow data
                    if ($result === 'validation_failed') {
                        return false; // Return false but don't delete flow data
                    }
                    
                    // If flow is delayed, don't delete flow data
                    if ($result === 'delayed') {
                        return false; // Return false but don't delete flow data
                    }
                    
                    return $result;
                }
            }

            return false;
        }
    }*/

    public function hasActiveFlow($chat){
        $activeFlow = FlowUserData::where('contact_id', $chat->contact_id)->first();

        return $activeFlow ? true : false;
    }

    /**
     * Continue a flow that was paused due to a delay action
     *
     * @param int $contactId
     * @param int $flowId
     * @param int $currentStep
     * @return bool
     */
    public function continueDelayedFlow($contactId, $flowId, $currentStep)
    {
        // Find the flow user data
        $flowData = FlowUserData::where('contact_id', $contactId)
            ->where('flow_id', $flowId)
            ->first();

        if (!$flowData) {
            Log::warning("FlowUserData not found for delayed flow continuation: contact {$contactId}, flow {$flowId}");
            return false;
        }

        // Update the current step if needed
        if ($flowData->current_step != $currentStep) {
            $flowData->current_step = $currentStep;
            $flowData->save();
        }

        // Get the contact
        $contact = Contact::find($contactId);
        if (!$contact) {
            Log::warning("Contact not found for delayed flow continuation: {$contactId}");
            return false;
        }

        // Create a mock chat object for processing
        $chat = (object) [
            'contact_id' => $contactId,
            'organization_id' => $this->organizationId
        ];

        // Continue processing the flow from the current step
        return $this->processFlow($chat, false, $flowData, $contactId, '');
    }

    public function processFlow($chat, $isNewContact, $flowData, $contactId, $message){
        $flow = Flow::find($flowData->flow_id);

        if (!$flow || empty($flow->metadata)) {
            return;
        }

        $contact = Contact::find($contactId);
        if (!$contact) {
            return false;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", []);
        $nodes = \Arr::get($edgesArray, "nodes", []);       
        
        // Process nodes continuously until we encounter a message node
        $maxIterations = 50; // Prevent infinite loops
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $iteration++;
            
            // Get the current node metadata
            $metadataArray = $this->findEdgesBySource($edges, $nodes, $flowData->current_step, $message);

            if(empty($metadataArray)){
                Log::warning("DELETION POINT 2: No next step found for contact {$contactId}, ending flow");
                FlowUserData::where('contact_id', $contactId)->delete();
                $this->executeFlow($chat, $isNewContact, $message);
                return false;
            }

            // Check if this is an action node
            $nodeType = \Arr::get($metadataArray, "type", null);
            if ($nodeType === 'action') {
                $result = $this->processActionNode($metadataArray, $contact, $message, $flowData, $contactId);
                
                if ($result === false) {
                    // Action failed, stop the flow
                    return false;
                }
                
                if ($result === 'validation_failed') {
                    // Validation failed (e.g., invalid email), stay on the same node
                    return 'validation_failed'; // Return special value to indicate validation failure
                }
                
                if ($result === 'delayed') {
                    // Flow is paused due to delay action, don't proceed to next step
                    return 'delayed'; // Return special value to indicate the flow is paused
                }
                
                // Action completed successfully, refresh flowData and continue to next node
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
                if (!$flowData) {
                    Log::warning("DELETION POINT 7: FlowUserData not found after action, ending flow");
                    return false;
                }
                continue;
            }

            // This is a message node (text, media, interactive, etc.) - process it and stop
            return $this->processMessageNode($metadataArray, $contact, $flowData, $contactId);
        }
        
        Log::warning("DELETION POINT 3: Maximum iterations reached for contact {$contactId}, ending flow");
        FlowUserData::where('contact_id', $contactId)->delete();
        return false;
    }

    /**
     * Process message nodes (text, media, interactive buttons, etc.)
     */
    private function processMessageNode($metadataArray, $contact, $flowData, $contactId)
    {
        $fieldsArray = \Arr::get($metadataArray, "data.metadata.fields", null);
        $type = $fieldsArray['type'] ?? null;

        $message = $this->replacePlaceholders($contact->uuid, $fieldsArray['body'] ?? '');

        // Initialize the header array if needed for interactive messages
        $header = $this->prepareHeader($fieldsArray ?? []);
        $buttonArray = [];
        $buttonType = null;
        $buttonLabel = null;

        if($type == 'text'){
            $buttonType = 'text';
        } elseif ($type === 'interactive buttons') {
            $buttonType = ($fieldsArray['buttonType'] ?? '') === 'buttons'
                ? 'interactive buttons'
                : 'interactive call to action url';
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);
    
        } elseif ($type === 'interactive list') {
            $buttonType = 'interactive list';
            $buttonArray = $this->prepareButtonArray($fieldsArray ?? [], $buttonType);
            $buttonLabel = $fieldsArray['buttonLabel'] ?? '';
        }

        $response = null;

        switch ($type) {
            case 'text':
                $response = $this->whatsappService->sendMessage($contact->uuid, $message, 0, $buttonType);
                break;
    
            case 'media':
                $mediaInfo = json_decode(($fieldsArray['media']['metadata'] ?? '{}'), true);
                $mediaLocation = $fieldsArray['media']['location'] ?? '';
                $mediaLocation = ($mediaLocation === 'aws') ? 'amazon' : $mediaLocation;

                $response = $this->whatsappService->sendMedia(
                    $contact->uuid,
                    $fieldsArray['mediaType'] ?? '',
                    $mediaInfo['name'] ?? '',
                    $fieldsArray['media']['path'] ?? '',
                    $fieldsArray['media']['path'] ?? '',
                    $mediaLocation,
                    $fieldsArray['caption'] ?? ''
                );
                break;
    
            case 'interactive buttons':
            case 'interactive list':
                $response = $this->whatsappService->sendMessage(
                    $contact->uuid,
                    $message,
                    0,
                    $buttonType,
                    $buttonArray,
                    $header,
                    ($fieldsArray['footer'] ?? ''),
                    $buttonLabel
                );
                break;
        }

        if($response){
            // Update to the next step
            $this->proceedToNextStep($flowData, $contactId, $metadataArray);
            
            if(isset($response->data->chat->id)){
                FlowLog::create([
                    'flow_id' => $flowData->flow_id,
                    'chat_id' => $response->data->chat->id
                ]);
            }
            
            return true;
        } else {
            // Even if message sending fails, we should still proceed to next step to avoid getting stuck
            $this->proceedToNextStep($flowData, $contactId);
            return true;
        }
    }

    /**
     * Process action nodes in the flow
     */
    private function processActionNode($metadataArray, $contact, $message, $flowData, $contactId)
    {
        $actionType = \Arr::get($metadataArray, "data.actionType", null);
        $config = \Arr::get($metadataArray, "data.config", []);
        $isActive = \Arr::get($metadataArray, "data.is_active", true);

        if (!$actionType || !$isActive) {
            // If action is not active or has no type, just proceed to next step
            $this->proceedToNextStep($flowData, $contactId);
            return true;
        }

        // Normalize action type: convert hyphens to underscores
        $actionType = str_replace('-', '_', $actionType);

        // Initialize action execution service
        $actionService = new ActionExecutionService($this->organizationId);

        // Execute the action
        $result = $actionService->executeAction($actionType, $config, $contact, $message, $flowData, $contactId);

        // Handle conditional actions specially
        if ($actionType === 'conditional') {
            // Set current_step to this conditional node's ID before processing
            $conditionalNodeId = $metadataArray['id'] ?? null;
            if ($conditionalNodeId && $flowData->current_step != $conditionalNodeId) {
                FlowUserData::where('contact_id', $contactId)->update(['current_step' => $conditionalNodeId]);
                $flowData = FlowUserData::where('contact_id', $contactId)->first();
            }
            return $this->handleConditionalAction($result, $flowData, $contactId, $metadataArray);
        }

        // For update_contact action, check if it failed due to validation BEFORE checking general failure
        if ($actionType === 'update_contact' && $result === false) {
            // Check if there's an invalid email message configured, which indicates validation failure
            $invalidMessage = $config['invalid_email_message'] ?? '';
            //Log::info("Update contact validation failed, staying on same node for contact {$contactId}");
            return 'validation_failed'; // Special return value to indicate validation failure
        }

        if ($result === false) {
            // Action failed, stop the flow
            Log::warning("DELETION POINT 4: Action failed, deleting FlowUserData for contact {$contactId}");
            FlowUserData::where('contact_id', $contactId)->delete();
            return false;
        }

        // For other actions, proceed to next step
        $this->proceedToNextStep($flowData, $contactId);
        return true;
    }

    /**
     * Handle conditional action branching
     */
    private function handleConditionalAction($conditionResult, $flowData, $contactId, $metadataArray)
    {
        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            return false;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", null);
        $currentStep = $flowData->current_step;

        // Build the sourceHandle for the condition or default
        if ($conditionResult !== 'default') {
            $sourceHandle = 'condition-' . $conditionResult . '|' . $currentStep;
        } else {
            $sourceHandle = 'default|' . $currentStep;
        }

        // \Log::info("Looking for edge with source: {$currentStep} and sourceHandle: {$sourceHandle}");

        foreach ($edges as $edge) {
            if (
                isset($edge['source']) && (string)$edge['source'] === (string)$currentStep &&
                isset($edge['sourceHandle']) && $edge['sourceHandle'] === $sourceHandle
            ) {
                $targetNode = $edge['targetNode']['id'] ?? $edge['target'] ?? null;
                if ($targetNode) {
                    // \Log::info("Found matching edge, routing to node: " . $targetNode);
                    // Reload flowData
                    $flowData = FlowUserData::where('contact_id', $contactId)->first();
                    // Get the chat object (you may need to pass it in, or reconstruct it)
                    $chat = (object) [
                        'contact_id' => $contactId,
                        'organization_id' => $this->organizationId
                    ];
                    // Process the next node
                    $this->processFlow($chat, false, $flowData, $contactId, '');
                    return true;
                }
            }
        }

        // No matching path found, end the flow
        \Log::warning("DELETION POINT 5: No matching conditional path found, deleting FlowUserData for contact {$contactId}");
        FlowUserData::where('contact_id', $contactId)->delete();
        return false;
    }

    /**
     * Proceed to the next step in the flow
     */
    private function proceedToNextStep($flowData, $contactId, $currentMetadataArray = null )
    {
        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            Log::warning("DELETION POINT 8: Flow not found or empty metadata for flow {$flowData->flow_id}");
            return;
        }

        $edgesArray = json_decode($flow->metadata, true);
        $edges = \Arr::get($edgesArray, "edges", []);
        $nodes = \Arr::get($edgesArray, "nodes", []);

        $nextNodes = [];
        foreach ($edges as $edge) {
            if (isset($edge['source']) && (string)$edge['source'] === (string)$flowData->current_step) {
                // Prefer nested targetNode, fallback to target id
                if (isset($edge['targetNode']['id'])) {
                    $nextNodes[] = $edge['targetNode']['id'];
                } elseif (isset($edge['target'])) {
                    $nextNodes[] = $edge['target'];
                }
            }
        }

        if (!empty($nextNodes)) {
            $nextStep = $nextNodes[0];
            if(!empty($currentMetadataArray) && isset($currentMetadataArray['id'])){
                $nextStep = $currentMetadataArray['id'] ?? $nextStep;
            }
            FlowUserData::where('contact_id', $contactId)->update(['current_step' => $nextStep]);
        } else {
            Log::warning("DELETION POINT 6: No next step found for current_step {$flowData->current_step}, ending flow");
            FlowUserData::where('contact_id', $contactId)->delete();
        }
    }

    private function prepareHeader(array $fieldsArray): array
    {
        $header = [];

        if (($fieldsArray['headerType'] ?? '') === 'text') {
            $header = [
                'type' => 'text',
                'text' => clean($fieldsArray['headerText'] ?? ''),
            ];
        } elseif (($fieldsArray['headerType'] ?? '') !== 'none') {
            $header['type'] = $fieldsArray['headerType'] ?? '';
            $header[$fieldsArray['headerType'] ?? ''] = [
                'link' => $fieldsArray['headerMedia']['path'] ?? '',
            ];
        }

        return $header;
    }

    private function prepareButtonArray(array $fieldsArray, string $type): array
    {
        $buttonArray = [];

        if ($type === 'interactive buttons') {
            foreach ($fieldsArray['buttons'] ?? [] as $id => $title) {
                if (!empty($title)) {
                    $buttonArray[] = [
                        'id' => $id,
                        'title' => $title,
                    ];
                }
            }
        } elseif ($type === 'interactive call to action url') {
            $buttonArray = [
                'display_text' => $fieldsArray['ctaUrlButton']['displayText'] ?? '',
                'url' => $fieldsArray['ctaUrlButton']['url'] ?? '',
            ];
        } elseif ($type === 'interactive list') {
            $buttonArray = collect($fieldsArray['sections'] ?? [])->map(function ($section) {
                return [
                    'title' => $section['title'] ?? '',
                    'rows' => collect($section['rows'] ?? [])->map(function ($row) {
                        return [
                            'id' => $row['id'] ?? '',
                            'title' => $row['title'] ?? '',
                            'description' => $row['description'] ?? '',
                        ];
                    })->all()
                ];
            })->all();
        }

        return $buttonArray;
    }

    /**
 * Find the next node(s) by source node id.
 *
 * This version:
 * - accepts $nodes array (so we can return the real node object)
 * - respects edges that have sourceHandle set (will not auto-follow them unless message maps to that handle)
 * - supports interactive buttons and lists by deriving handle from $message
 *
 * @param array $edges
 * @param array $nodes  Entire nodes array from flow metadata
 * @param string|int $sourceId
 * @param string $message
 * @return array  Returns the target node array (node object) or [] when none found.
 */
    private function findEdgesBySource(array $edges, array $nodes, $sourceId, string $message = ''): array
    {
        $sourceId = (string)$sourceId;
        $matchingEdges = [];

        // collect edges that originate from this source
        foreach ($edges as $edge) {
            if (!isset($edge['source'])) {
                continue;
            }
            if ((string)$edge['source'] === $sourceId) {
                $matchingEdges[] = $edge;
            }
        }

        \Log::info("findEdgesBySource: source={$sourceId}, total_matching_edges=" . count($matchingEdges));

        if (count($matchingEdges) === 0) {
            return [];
        }

        // Get source node (to inspect buttons / sections if needed)
        $sourceNode = [];
        // Prefer edge-provided sourceNode, fallback to nodes array
        if (isset($matchingEdges[0]['sourceNode']) && is_array($matchingEdges[0]['sourceNode'])) {
            $sourceNode = $matchingEdges[0]['sourceNode'];
        } else {
            $sourceNode = $this->getNodeById($nodes, $sourceId);
        }

        // If there's only one edge:
        if (count($matchingEdges) === 1) {
            $edge = $matchingEdges[0];

            // If edge requires a specific handle (e.g. "c"), ensure message maps to it.
            if (isset($edge['sourceHandle']) && $edge['sourceHandle'] !== null) {
                $derivedHandle = $this->deriveHandleFromMessage($sourceNode, $message);
                \Log::info("Single edge requires handle={$edge['sourceHandle']}, derivedHandle=" . ($derivedHandle ?? 'null'));
                if ($derivedHandle === null || $derivedHandle !== $edge['sourceHandle']) {
                    // user did not select the required handle — do not follow this edge.
                    return [];
                }
            }

            // Resolve target node and return
            $targetNodeObj = $edge['targetNode'] ?? null;
            if (is_array($targetNodeObj) && isset($targetNodeObj['id'])) {
                return $targetNodeObj;
            }

            $targetId = $edge['target'] ?? null;
            if ($targetId !== null) {
                return $this->getNodeById($nodes, $targetId);
            }

            return [];
        }

        // Multiple outgoing edges — need to resolve by handle/message/type
        // Use the first matched edge to inspect sourceNode type / fields
        $firstEdge = $matchingEdges[0];
        $nodeType = $firstEdge['sourceNode']['type'] ?? $sourceNode['type'] ?? null;
        $fieldsType = \Arr::get($sourceNode, 'data.metadata.fields.type', null);

        // Action nodes: automatic progression — take the first edge
        if ($nodeType === 'action') {
            $edge = $matchingEdges[0];
            $targetNodeObj = $edge['targetNode'] ?? null;
            if (is_array($targetNodeObj) && isset($targetNodeObj['id'])) {
                return $targetNodeObj;
            }
            $targetId = $edge['target'] ?? null;
            return $this->getNodeById($nodes, $targetId);
        }

        // If interactive buttons, try to map message -> handle then find matching edge
        if ($fieldsType === 'interactive buttons' || $nodeType === 'buttons') {
            $derivedHandle = $this->deriveHandleFromMessage($sourceNode, $message);
            \Log::info("Interactive buttons: derivedHandle=" . ($derivedHandle ?? 'null'));

            if ($derivedHandle !== null) {
                foreach ($matchingEdges as $edge) {
                    if (isset($edge['sourceHandle']) && $edge['sourceHandle'] === $derivedHandle) {
                        // return the edge's target node
                        $targetNodeObj = $edge['targetNode'] ?? null;
                        if (is_array($targetNodeObj) && isset($targetNodeObj['id'])) {
                            return $targetNodeObj;
                        }
                        $targetId = $edge['target'] ?? null;
                        return $this->getNodeById($nodes, $targetId);
                    }
                }
            }

            // no matching handle found
            return [];
        }

        // If interactive list, attempt to derive handle from rows similarly
        if ($fieldsType === 'interactive list') {
            $derivedHandle = $this->deriveHandleFromMessage($sourceNode, $message);
            \Log::info("Interactive list: derivedHandle=" . ($derivedHandle ?? 'null'));

            if ($derivedHandle !== null) {
                foreach ($matchingEdges as $edge) {
                    if (isset($edge['sourceHandle']) && $edge['sourceHandle'] === $derivedHandle) {
                        $targetNodeObj = $edge['targetNode'] ?? null;
                        if (is_array($targetNodeObj) && isset($targetNodeObj['id'])) {
                            return $targetNodeObj;
                        }
                        $targetId = $edge['target'] ?? null;
                        return $this->getNodeById($nodes, $targetId);
                    }
                }
            }
            return [];
        }

        // Default: can't determine correct branch -> return empty (do not auto-follow)
        \Log::info("Multiple edges found but unable to resolve a branch for source={$sourceId} message={$message}");
        return [];
    }

    private function replacePlaceholders($contactUuid, $message){
        $organization = Organization::where('id', $this->organizationId)->first();
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

        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($mergedData) {
            $key = $matches[1];
            return isset($mergedData[$key]) ? $mergedData[$key] : $matches[0];
        }, $message);
    }

    /**
 * Helper: find a node object inside the flow 'nodes' array by id
 */
private function getNodeById(array $nodes, $id): array
{
    foreach ($nodes as $node) {
        if ((string)($node['id'] ?? '') === (string)$id) {
            return $node;
        }
    }
    return [];
}

/**
 * Helper: derive a sourceHandle (a, b, c, a01 etc) from the node definition + user message.
 * Returns NULL if we cannot derive a handle from the message.
 */
private function deriveHandleFromMessage(array $sourceNode, string $message): ?string
{
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return null;
    }

    // 1) Buttons mapping
    $buttons = \Arr::get($sourceNode, 'data.metadata.fields.buttons', []);
    if (!empty($buttons) && is_array($buttons)) {
        // mapping: button1 -> a, button2 -> b, button3 -> c
        $buttonToHandle = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];

        foreach ($buttons as $key => $label) {
            if (!is_string($label)) continue;
            if (strtolower(trim($label)) === $msg) {
                return $buttonToHandle[$key] ?? null;
            }
            // allow matching the key itself (e.g. "button1")
            if (strtolower(trim($key)) === $msg) {
                return $buttonToHandle[$key] ?? null;
            }
        }

        // allow direct handle input (user might send 'a','b','c')
        if (in_array($msg, ['a','b','c'])) {
            return $msg;
        }
    }

    // 2) Interactive list: search rows' titles
    $sections = \Arr::get($sourceNode, 'data.metadata.fields.sections', []);
    if (!empty($sections) && is_array($sections)) {
        foreach ($sections as $sIndex => $section) {
            $rows = $section['rows'] ?? [];
            if (!is_array($rows)) continue;
            foreach ($rows as $rIndex => $row) {
                $title = strtolower(trim($row['title'] ?? ''));
                if ($title !== '' && $title === $msg) {
                    // handle format used by your code: a{sectionIndex}{rowIndex}
                    return 'a' . $sIndex . $rIndex;
                }
            }
        }
    }

    // no handle derived
    return null;
}
}