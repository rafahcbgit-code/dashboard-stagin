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
use App\Services\TriggerEvaluationService;
use App\Services\TriggerGroupService;
use App\Services\RateLimitService;
use App\Services\BotSettingsService;
use App\Services\AiService;
use App\Services\AiFallbackService;
use App\Services\AiKnowledgeBaseService;
use App\Services\AiEscalationInstructionService;
use App\Services\AiSettingsService;
use App\Services\AgentRoutingService;

class AutoReplyService
{
    protected $workingHoursService;
    protected $triggerEvaluationService;
    protected $rateLimitService;
    protected $triggerGroupService;
    protected $aiFallbackService;
    protected $botSettingsService;

    public $metaPayload ;

    public function __construct($metaPayload = null)
    {
        $this->workingHoursService = new WorkingHoursService();
        $aiService = new AiService();
        $intentClassifier = new AiIntentClassifier($aiService);
        $this->botSettingsService = new BotSettingsService();
        $aiSettingsService = new AiSettingsService();
        $this->rateLimitService = new RateLimitService($this->botSettingsService);
        $this->triggerGroupService = new TriggerGroupService();
        $this->triggerEvaluationService = new TriggerEvaluationService(
            $intentClassifier,
            $this->rateLimitService,
            $this->workingHoursService,
            $aiSettingsService
        );
        $this->aiFallbackService = new AiFallbackService(
            $aiService,
            new AiKnowledgeBaseService(),
            $this->botSettingsService,
            $aiSettingsService,
            new AiEscalationInstructionService($aiService, $aiSettingsService)
        );
        $this->metaPayload = $metaPayload;

        logger($this->metaPayload);
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
        if ($request->response_type === 'image' || $request->response_type === 'audio') {
            if ($request->hasFile('response')) {
                $storage = Setting::where('key', 'storage_system')->first()->value;
                $fileName = $request->file('response')->getClientOriginalName();
                $fileContent = $request->file('response');

                if ($storage === 'local') {
                    $file = Storage::disk('local')->put('public', $fileContent);
                    $mediaFilePath = $file;
                    $mediaUrl = rtrim(config('app.url'), '/') . '/media/' . ltrim($mediaFilePath, '/');
                } else if ($storage === 'aws') {
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
        } else if ($request->response_type === 'text') {
            $metadata['data']['text'] = $request->response;
        } else {
            $metadata['data']['template'] = $request->response;
        }

        $model['metadata'] = json_encode($metadata);
        $model['updated_at'] = now();

        if ($uuid === null) {
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
    {
        $organizationId = $chat->organization_id;

        $this->replySequence($organizationId, $chat, $isNewContact);
    }

    private function replySequence($organizationId, $chat, $isNewContact)
    {
        // Check if this is an interactive button click (fallback menu or AI helpful feedback)
        $buttonResult = $this->handleInteractiveButtonCheck($organizationId, $chat);
        if ($buttonResult) {
            return $buttonResult;
        }

        // AI-Only Mode: AI answers from KB directly, fallback menu for unanswered
        $aiSettingsService = new AiSettingsService();
        if ($aiSettingsService->isAiOnlyMode($organizationId)) {
            Log::info('replySequence: AI-Only Mode active, attempting AI answer', [
                'organization_id' => $organizationId,
            ]);

            // Try AI answer from knowledge base
            $aiFallbackResult = $this->handleAiFallback($organizationId, $chat);
            if ($aiFallbackResult) {
                return $aiFallbackResult;
            }

            // AI couldn't answer — show fallback menu
            $fallbackMenuResult = $this->handleFallbackMenu($organizationId, $chat);
            if ($fallbackMenuResult) {
                return $fallbackMenuResult;
            }

            return null;
        }

        $organizationConfig = Organization::where('id', $organizationId)->first();
        $metadataArray = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];
        $activeFlow = false;
        $modulePath = base_path('modules/FlowBuilder');

        if (File::exists($modulePath)) {
            if (class_exists(\Modules\FlowBuilder\Services\FlowExecutionService::class)) {
                $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId, $this->metaPayload);
                $activeFlow = $query->hasActiveFlow($chat);
            }
        }

        // Override response sequence if there is an active flow
        if ($activeFlow) {
            $response_sequence = ['Automated Flows'];
        } else {
            // NEW: Evaluate trigger groups first (before the traditional sequence)
            $triggerResult = $this->handleTriggerEvaluation($organizationId, $chat, $isNewContact);
            if ($triggerResult) {
                return $triggerResult;
            }

            // NEW: AI Fallback — attempt KB answer when no trigger matched
            $aiFallbackResult = $this->handleAiFallback($organizationId, $chat);
            if ($aiFallbackResult) {
                return $aiFallbackResult;
            }

            // Use the response sequence from metadata or fallback to default
            $response_sequence = $metadataArray['automation']['response_sequence'] ?? ['Basic Replies', 'Automated Flows'];
        }

        // Define mapping of sequence items to functions
        $sequenceFunctions = [
            'Basic Replies' => function () use ($chat) {
                return $this->handleBasicReplies($chat);
            },
            'Automated Flows' => function () use ($organizationId, $chat, $isNewContact) {
                return $this->handleAutomatedFlows($organizationId, $chat, $isNewContact);
            },
            'AI Reply Assistant' => function () use ($chat) {
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

        // If nothing matched, try the fallback menu
        if (!$response) {
            $fallbackMenuResult = $this->handleFallbackMenu($organizationId, $chat);
            if ($fallbackMenuResult) {
                return $fallbackMenuResult;
            }
        }

        return $response;
    }

    /**
     * Evaluate trigger groups for the organization.
     * If a trigger matches, start the linked flow and record the rate limit.
     *
     * @return bool True if a trigger matched and a flow was started
     */
    private function handleTriggerEvaluation(int $organizationId, Chat $chat, bool $isNewContact): bool
    {
        try {
            // Ensure system triggers are initialized
            $this->triggerGroupService->initializeSystemTriggers($organizationId);

            // Extract message text
            $message = $this->extractMessageText($chat);

            // Get the contact
            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            // Evaluate triggers in priority order
            $result = $this->triggerEvaluationService->evaluate(
                $organizationId,
                $contact,
                $message,
                $isNewContact
            );

            if ($result === null) {
                return false;
            }

            // Record the trigger in rate limits
            $this->rateLimitService->recordTrigger(
                $organizationId,
                $contact->id,
                $result->triggerGroup->id
            );

            // If the matched trigger has a linked flow, execute it
            if ($result->flow) {
                $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                    $organizationId,
                    $this->metaPayload
                );
                return $flowExecutionService->startFlow($result->flow->id, $chat);
            }

            // If it's a fallback trigger with no flow, let it fall through to global reply
            if ($result->triggerGroup->type === 'fallback') {
                return false;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Trigger evaluation failed', [
                'organization_id' => $organizationId,
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Handle AI Fallback — attempt to answer from Knowledge Base when no trigger matched.
     *
     * @return bool True if AI provided an answer
     */
    private function handleAiFallback(int $organizationId, Chat $chat): bool
    {
        try {
            $message = $this->extractMessageText($chat);

            if (empty(trim($message))) {
                return false;
            }

            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            $result = $this->aiFallbackService->attemptAnswer($organizationId, $message, $contact);

            if ($result === null) {
                return false;
            }

            if ($result['type'] === 'escalation') {
                // Escalation matched — route to human agent
                $instruction = $result['instruction'];
                Log::info('AI Fallback: Escalation triggered', [
                    'organization_id' => $organizationId,
                    'contact_id' => $contact->id,
                    'instruction_name' => $instruction->name,
                    'target_type' => $instruction->target_type,
                ]);

                $routingService = new AgentRoutingService();
                $routingService->assignToAgent(
                    $organizationId,
                    $contact->id,
                    $instruction->target_type,
                    $instruction->target_agent_id
                );

                return true;
            }

            // AI answer — send via WhatsApp
            $organizationConfig = Organization::where('id', $organizationId)->first();
            $metadata = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

            $whatsappService = new WhatsappService(
                $metadata['whatsapp']['access_token'] ?? null,
                config('graph.api_version'),
                $metadata['whatsapp']['app_id'] ?? null,
                $metadata['whatsapp']['phone_number_id'] ?? null,
                $metadata['whatsapp']['waba_id'] ?? null,
                $organizationId
            );

            $whatsappService->sendMessage(
                $contact->uuid,
                $result['content'],
                null,
                'text'
            );

            // Send "Was this helpful?" follow-up buttons
            $helpfulButtons = [
                ['id' => 'ai_helpful_yes', 'title' => 'Yes, this helped'],
                ['id' => 'ai_helpful_no', 'title' => 'No, I need more help'],
            ];

            $whatsappService->sendMessage(
                $contact->uuid,
                'Was this helpful?',
                null,
                'interactive buttons',
                $helpfulButtons
            );

            Log::info('AI Fallback: Answer sent with feedback buttons', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('AI Fallback handler failed', [
                'organization_id' => $organizationId,
                'chat_id' => $chat->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Handle Fallback Menu — send 3-button interactive WhatsApp message when nothing else matches.
     *
     * @return bool True if fallback menu was sent
     */
    private function handleFallbackMenu(int $organizationId, Chat $chat): bool
    {
        try {
            if (!$this->botSettingsService->isFallbackMenuEnabled($organizationId)) {
                return false;
            }

            $config = $this->botSettingsService->getFallbackMenuConfig($organizationId);
            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            // Check if fallback counter exceeds threshold — auto-escalate to agent
            if ($this->rateLimitService->shouldEscalate($organizationId, $contact->id)) {
                Log::info('Fallback Menu: Counter exceeded threshold, auto-escalating to agent', [
                    'organization_id' => $organizationId,
                    'contact_id' => $contact->id,
                ]);

                $routingService = new AgentRoutingService();
                $routingService->assignToAgent($organizationId, $contact->id, 'auto_assignment');

                $this->initializeWhatsappService($organizationId)->sendMessage(
                    $contact->uuid,
                    "You're being connected to a team member who can help. Please hold on a moment.",
                    null,
                    'text'
                );
                return true;
            }

            $buttons = [
                ['id' => 'fallback_start_over', 'title' => 'Start Over'],
                ['id' => 'fallback_help', 'title' => 'Help'],
            ];

            if ($config['route_to_agent']) {
                $buttons[] = ['id' => 'fallback_talk_to_agent', 'title' => 'Talk to Agent'];
            }

            $message = $config['message'] ?: "I didn't quite understand that. What would you like to do?";

            $this->initializeWhatsappService($organizationId)->sendMessage(
                $contact->uuid,
                $message,
                null,
                'interactive buttons',
                $buttons
            );

            // Record fallback trigger for rate limiting / escalation counting
            $this->rateLimitService->recordFallback($organizationId, $contact->id);

            Log::info('Fallback Menu sent', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('Fallback Menu handler failed', [
                'organization_id' => $organizationId,
                'chat_id' => $chat->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Handle a fallback menu button response from WhatsApp.
     * Called from the webhook when a button_reply with id starting with "fallback_" is received.
     */
    public function handleFallbackButtonResponse(int $organizationId, Chat $chat, string $buttonId): bool
    {
        try {
            $config = $this->botSettingsService->getFallbackMenuConfig($organizationId);
            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                $organizationId,
                $this->metaPayload
            );

            switch ($buttonId) {
                case 'fallback_start_over':
                    if ($config['start_over_flow_id']) {
                        return $flowExecutionService->startFlow($config['start_over_flow_id'], $chat);
                    }
                    // If no flow configured, try to trigger the first/new_contact trigger
                    $this->triggerGroupService->initializeSystemTriggers($organizationId);
                    $result = $this->triggerEvaluationService->evaluate(
                        $organizationId,
                        $contact,
                        '',
                        true // treat as new contact to get the welcome flow
                    );
                    if ($result && $result->flow) {
                        return $flowExecutionService->startFlow($result->flow->id, $chat);
                    }
                    return false;

                case 'fallback_help':
                    if ($config['help_flow_id']) {
                        return $flowExecutionService->startFlow($config['help_flow_id'], $chat);
                    }
                    return false;

                case 'fallback_talk_to_agent':
                    if ($config['route_to_agent']) {
                        Log::info('Fallback Menu: Talk to Agent requested', [
                            'organization_id' => $organizationId,
                            'contact_id' => $contact->id,
                        ]);

                        $routingService = new AgentRoutingService();
                        $routingService->assignToAgent($organizationId, $contact->id, 'auto_assignment');

                        return true;
                    }
                    return false;

                default:
                    return false;
            }
        } catch (\Throwable $e) {
            Log::error('Fallback button response handler failed', [
                'organization_id' => $organizationId,
                'chat_id' => $chat->id ?? null,
                'button_id' => $buttonId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if the incoming message is an interactive button click (fallback or AI helpful feedback).
     * If so, route it to the appropriate handler and return the result.
     */
    private function handleInteractiveButtonCheck(int $organizationId, Chat $chat): ?bool
    {
        $metadata = json_decode($chat->metadata, true);
        if (!$metadata || !isset($metadata['type']) || $metadata['type'] !== 'interactive') {
            return null;
        }

        $interactive = $metadata['interactive'] ?? null;
        if (!$interactive || !isset($interactive['type']) || $interactive['type'] !== 'button_reply') {
            return null;
        }

        $buttonId = $interactive['button_reply']['id'] ?? '';

        // Fallback menu button clicks
        if (strpos($buttonId, 'fallback_') === 0) {
            return $this->handleFallbackButtonResponse($organizationId, $chat, $buttonId);
        }

        // AI "Was this helpful?" button clicks
        if ($buttonId === 'ai_helpful_yes' || $buttonId === 'ai_helpful_no') {
            return $this->handleAiHelpfulButton($organizationId, $chat, $buttonId);
        }

        return null;
    }

    /**
     * Handle AI "Was this helpful?" button response.
     * Yes → acknowledge, No → show fallback menu.
     */
    private function handleAiHelpfulButton(int $organizationId, Chat $chat, string $buttonId): bool
    {
        try {
            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            if ($buttonId === 'ai_helpful_yes') {
                // User confirmed the answer was helpful — send a quick acknowledgment
                $organizationConfig = Organization::where('id', $organizationId)->first();
                $metadata = $organizationConfig->metadata ? json_decode($organizationConfig->metadata, true) : [];

                $whatsappService = new WhatsappService(
                    $metadata['whatsapp']['access_token'] ?? null,
                    config('graph.api_version'),
                    $metadata['whatsapp']['app_id'] ?? null,
                    $metadata['whatsapp']['phone_number_id'] ?? null,
                    $metadata['whatsapp']['waba_id'] ?? null,
                    $organizationId
                );

                $whatsappService->sendMessage(
                    $contact->uuid,
                    'Great! Let me know if you need anything else.',
                    null,
                    'text'
                );

                Log::info('AI Helpful: User confirmed answer was helpful', [
                    'organization_id' => $organizationId,
                    'contact_id' => $contact->id,
                ]);

                return true;
            }

            // ai_helpful_no — show the fallback menu instead
            Log::info('AI Helpful: User said answer was not helpful, showing fallback menu', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
            ]);

            return $this->handleFallbackMenu($organizationId, $chat);
        } catch (\Throwable $e) {
            Log::error('AI Helpful button handler failed', [
                'organization_id' => $organizationId,
                'chat_id' => $chat->id ?? null,
                'button_id' => $buttonId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extract message text from chat metadata.
     */
    private function extractMessageText(Chat $chat): string
    {
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if (isset($metadata['type'])) {
            if ($metadata['type'] == 'text') {
                $text = $metadata['text']['body'] ?? '';
            } else if ($metadata['type'] == 'button') {
                $text = $metadata['button']['payload'] ?? '';
            } else if ($metadata['type'] == 'interactive') {
                if (isset($metadata['interactive']['type'])) {
                    if ($metadata['interactive']['type'] == 'button_reply') {
                        $text = $metadata['interactive']['button_reply']['title'] ?? '';
                    } else if ($metadata['interactive']['type'] == 'list_reply') {
                        $text = $metadata['interactive']['list_reply']['title'] ?? '';
                    }
                }
            }
        }

        return $text;
    }

    private function handleBasicReplies($chat)
    {
        $organizationId = $chat->organization_id;
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if ($metadata['type'] == 'text') {
            $text = $metadata['text']['body'];
        } else if (json_decode($chat->metadata)->type == 'button') {
            $text = $metadata['button']['payload'];
        } else if (json_decode($chat->metadata)->type == 'interactive') {
            if ($metadata['interactive']['type'] == 'button_reply') {
                $text = $metadata['interactive']['button_reply']['title'];
            } else if ($metadata['interactive']['type'] == 'list_reply') {
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
        if ($this->sendGlobalReply($chat)) {
            return true;
        }

        return false; // No reply was sent
    }

    private function handleAIReplyAssistant($chat)
    {
        $text = '';
        $metadata = json_decode($chat->metadata, true);

        if ($metadata['type'] == 'text') {
            $text = $metadata['text']['body'];
        } else if (json_decode($chat->metadata)->type == 'button') {
            $text = $metadata['button']['payload'];
        } else if (json_decode($chat->metadata)->type == 'interactive') {
            if ($metadata['interactive']['type'] == 'button_reply') {
                $text = $metadata['interactive']['button_reply']['title'];
            } else if ($metadata['interactive']['type'] == 'list_reply') {
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

        if ($metadata['type'] == 'text') {
            $text = $metadata['text']['body'];
        } else if (json_decode($chat->metadata)->type == 'button') {
            $text = $metadata['button']['payload'];
        } else if (json_decode($chat->metadata)->type == 'interactive') {
            if ($metadata['interactive']['type'] == 'button_reply') {
                $text = $metadata['interactive']['button_reply']['title'];
            } else if ($metadata['interactive']['type'] == 'list_reply') {
                $text = $metadata['interactive']['list_reply']['title'];
            }
        }

        $receivedMessage = " " . $text;

        if (file_exists(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'))) {
            $service = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId, $this->metaPayload);
            return $service->resumeActiveFlow($chat, $receivedMessage);
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

        if ($replyType === 'text') {
            $message = $this->replacePlaceholders($organization_id, $contact->uuid, $metadata->data->text);
            $this->initializeWhatsappService($organization_id)->sendMessage($contact->uuid, $message);
        } else if ($replyType === 'audio' || $replyType === 'image') {
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

    private function replacePlaceholders($organizationId, $contactUuid, $message)
    {
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
        if ($metadata) {
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
        if (
            !isset($metadata['global_reply']) ||
            !$metadata['global_reply']['active'] ||
            empty($metadata['global_reply']['message'])
        ) {
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
                    'contact_id' => $chat->contact_id,
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

    public function sendGrettingMessage($chat)
    {
        try {
            $organizationId = $chat->organization_id;

            Log::info('sendGrettingMessage - Starting process', [
                'chat_id' => $chat->id,
                'contact_id' => $chat->contact_id,
                'organization_id' => $organizationId,
            ]);

            // Check if greeting message was already sent within the last 24 hours
            if ($this->alreadySentGreetingMessage($chat)) {
                Log::info('sendGrettingMessage skipped - already sent within 24 hours', [
                    'contact_id' => $chat->contact_id,
                ]);
                return false;
            }

            // Get organization settings
            $organization = Organization::where('id', $organizationId)->first();
            if (!$organization) {
                Log::warning('sendGrettingMessage skipped - organization not found', [
                    'organization_id' => $organizationId
                ]);
                return false;
            }

            if (!$organization->metadata) {
                Log::warning('sendGrettingMessage skipped - organization metadata is null', [
                    'organization_id' => $organizationId
                ]);
                return false;
            }

            $metadata = json_decode($organization->metadata, true);

            // Check if greeting message is active
            if (!isset($metadata['greeting_message'])) {
                Log::warning('sendGrettingMessage skipped - greeting_message key not found in metadata', [
                    'organization_id' => $organizationId
                ]);
                return false;
            }

            if (!$metadata['greeting_message']['active']) {
                Log::info('sendGrettingMessage skipped - greeting_message is not active', [
                    'organization_id' => $organizationId
                ]);
                return false;
            }

            if (empty($metadata['greeting_message']['message'])) {
                Log::warning('sendGrettingMessage skipped - greeting_message message is empty', [
                    'organization_id' => $organizationId
                ]);
                return false;
            }

            $gretting_message = $metadata['greeting_message'];
            $contact = Contact::where('id', $chat->contact_id)->first();

            if (!$contact) {
                Log::warning('sendGrettingMessage skipped - contact not found', [
                    'contact_id' => $chat->contact_id
                ]);
                return false;
            }

            Log::info('sendGrettingMessage - All checks passed, sending message', [
                'contact_id' => $chat->contact_id,
            ]);

            // Send interactive message with buttons if buttons exist
            if (!empty($gretting_message['buttons'])) {
                $buttons = [];
                foreach ($gretting_message['buttons'] as $button) {
                    $buttons[] = [
                        'id' => $button['payload'],
                        'title' => $button['title']
                    ];
                }

                Log::info('sendGrettingMessage - Sending message with buttons', [
                    'button_count' => count($buttons)
                ]);

                $this->initializeWhatsappService($organizationId)->sendMessage(
                    $contact->uuid,
                    $gretting_message['message'],
                    null,
                    'interactive buttons',
                    $buttons
                );
            } else {
                Log::info('sendGrettingMessage - Sending simple text message');

                // Send simple text message
                $this->initializeWhatsappService($organizationId)->sendMessage(
                    $contact->uuid,
                    $gretting_message['message']
                );
            }

            // Save system message to prevent duplicate sends within 24 hours
            try {
                Log::info('Creating ChatSystemMessage (greeting_message)', [
                    'contact_id' => $chat->contact_id,
                ]);

                ChatSystemMessage::create([
                    'contact_id' => $chat->contact_id,
                    'type'       => 'greeting_message',
                    'sent_at'    => now(),
                ]);
            } catch (\Throwable $e) {
                // Failing to save the system message should NOT block the greeting
                Log::error('Failed to create ChatSystemMessage (greeting_message)', [
                    'error'      => $e->getMessage(),
                    'contact_id' => $chat->contact_id,
                ]);
            }

            Log::info('sendGrettingMessage - Successfully completed', [
                'contact_id' => $chat->contact_id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('sendGrettingMessage failed unexpectedly', [
                'chat_id'         => $chat->id ?? null,
                'contact_id'      => $chat->contact_id ?? null,
                'organization_id' => $chat->organization_id ?? null,
                'error'           => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function alreadySentGreetingMessage(Chat $chat): bool
    {
        try {
            // Get the last greeting message sent to this contact
            $last = ChatSystemMessage::where('contact_id', $chat->contact_id)
                ->where('type', 'greeting_message')
                ->orderByDesc('sent_at')
                ->first();

            // If no previous message exists, it was not sent before
            if (!$last) {
                return false;
            }

            // Check if the last greeting was sent within the last 24 hours
            return $last->sent_at->greaterThan(now()->subHours(24));
        } catch (\Throwable $e) {
            Log::warning('alreadySentGreetingMessage check failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
