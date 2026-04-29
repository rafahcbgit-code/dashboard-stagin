<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

    public $metaPayload;

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

        // Check for active flow — if contact is in a flow, resume it
        $activeFlow = false;
        $modulePath = base_path('modules/FlowBuilder');

        if (File::exists($modulePath)) {
            if (class_exists(\Modules\FlowBuilder\Services\FlowExecutionService::class)) {
                $query = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId, $this->metaPayload);
                $activeFlow = $query->hasActiveFlow($chat);
            }
        }

        if ($activeFlow) {
            // Resume the active flow
            $text = $this->extractMessageText($chat);
            if (File::exists(base_path('modules/FlowBuilder/Services/FlowExecutionService.php'))) {
                $service = new \Modules\FlowBuilder\Services\FlowExecutionService($organizationId, $this->metaPayload);
                $result = $service->resumeActiveFlow($chat, $text);
                if ($result) {
                    return true;
                }
            }

            // Active flow didn't handle the message — fall through to AI/fallback
        }

        // Evaluate trigger groups (greeting, new_contact, away, fallback, new_message)
        $triggerResult = $this->handleTriggerEvaluation($organizationId, $chat, $isNewContact);
        if ($triggerResult) {
            return $triggerResult;
        }

        // AI Fallback — attempt KB answer when no trigger matched
        $aiFallbackResult = $this->handleAiFallback($organizationId, $chat);
        if ($aiFallbackResult) {
            return $aiFallbackResult;
        }

        // Nothing matched — show fallback menu if enabled
        $fallbackMenuResult = $this->handleFallbackMenu($organizationId, $chat);
        if ($fallbackMenuResult) {
            return $fallbackMenuResult;
        }

        return null;
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

            // If it's a fallback trigger with no flow, let it fall through
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
}