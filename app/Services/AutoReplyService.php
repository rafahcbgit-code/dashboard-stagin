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

        // AI-Only Mode: AI answers from KB directly, then fall through to triggers
        $aiSettingsService = new AiSettingsService();
        if ($aiSettingsService->isAiOnlyMode($organizationId)) {
            // Guard: AI-only mode requires AI to be enabled
            if (!$aiSettingsService->isEnabled($organizationId)) {
                Log::warning('replySequence: AI-Only Mode active but AI is disabled, falling through to normal processing', [
                    'organization_id' => $organizationId,
                ]);
                // Fall through to normal trigger/AI processing
            } else {
                Log::info('replySequence: AI-Only Mode active, attempting AI answer', [
                    'organization_id' => $organizationId,
                ]);

                // Try AI answer from knowledge base
                $aiFallbackResult = $this->handleAiFallback($organizationId, $chat);
                if ($aiFallbackResult) {
                    return $aiFallbackResult;
                }

                // AI couldn't answer — fall through to trigger evaluation
            }
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

        // Nothing matched — no further handling
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

            // If it's a fallback trigger, check escalation threshold before executing
            if ($result->triggerGroup->type === 'fallback') {
                // Check if fallback counter exceeds threshold — auto-escalate to agent
                if ($this->rateLimitService->shouldEscalate($organizationId, $contact->id)) {
                    Log::info('Fallback trigger: Threshold exceeded, auto-escalating to agent', [
                        'organization_id' => $organizationId,
                        'contact_id' => $contact->id,
                    ]);

                    $routingService = new AgentRoutingService();
                    $routingService->assignToAgent($organizationId, $contact->id, 'auto_assignment');

                    $transferMessage = $this->botSettingsService->getSetting(
                        $organizationId,
                        'escalation_transfer_message',
                        "You're being connected to a team member who can help. Please hold on a moment."
                    );
                    $this->initializeWhatsappService($organizationId)->sendMessage(
                        $contact->uuid,
                        $transferMessage,
                        null,
                        'text'
                    );

                    return true;
                }

                // If has a linked flow, start it
                if ($result->flow) {
                    $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                        $organizationId,
                        $this->metaPayload
                    );
                    return $flowExecutionService->startFlow($result->flow->id, $chat);
                }

                // Fallback trigger matched but no flow and no escalation — fall through
                return false;
            }

            // If the matched trigger has a linked flow, execute it
            if ($result->flow) {
                $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                    $organizationId,
                    $this->metaPayload
                );
                return $flowExecutionService->startFlow($result->flow->id, $chat);
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

                // Notify the user they're being connected to an agent
                $transferMessage = $this->botSettingsService->getSetting(
                    $organizationId,
                    'escalation_transfer_message',
                    "You're being connected to a team member who can help. Please hold on a moment."
                );
                $this->initializeWhatsappService($organizationId)->sendMessage(
                    $contact->uuid,
                    $transferMessage,
                    null,
                    'text'
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
     * Handle a legacy fallback menu button response from WhatsApp.
     * These button IDs (fallback_start_over, fallback_help, fallback_talk_to_agent)
     * come from the old fallback menu that has been removed. This handler provides
     * backward compatibility for users who received the old menu.
     *
     * @deprecated The fallback menu has been replaced by the fallback trigger.
     */
    private function handleLegacyFallbackButtonResponse(int $organizationId, Chat $chat, string $buttonId): bool
    {
        try {
            Log::info('Legacy fallback button response received (fallback menu is deprecated)', [
                'organization_id' => $organizationId,
                'button_id' => $buttonId,
            ]);

            $contact = Contact::where('id', $chat->contact_id)->first();
            if (!$contact) {
                return false;
            }

            $this->triggerGroupService->initializeSystemTriggers($organizationId);
            $fallbackTrigger = \App\Models\TriggerGroup::forOrganization($organizationId)
                ->byType('fallback')
                ->first();

            switch ($buttonId) {
                case 'fallback_start_over':
                    // Reset fallback counter so escalation counting restarts
                    $this->rateLimitService->resetFallbackCounter($organizationId, $contact->id);

                    // If the fallback trigger has a flow, start it
                    if ($fallbackTrigger && $fallbackTrigger->flow_id) {
                        $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                            $organizationId,
                            $this->metaPayload
                        );
                        return $flowExecutionService->startFlow($fallbackTrigger->flow_id, $chat);
                    }

                    // Otherwise, try to trigger the new_contact trigger
                    $newContactTrigger = \App\Models\TriggerGroup::forOrganization($organizationId)
                        ->byType('new_contact')
                        ->first();
                    if ($newContactTrigger) {
                        $this->rateLimitService->resetTrigger($organizationId, $contact->id, $newContactTrigger->id);
                    }

                    $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                        $organizationId,
                        $this->metaPayload
                    );
                    $result = $this->triggerEvaluationService->evaluate(
                        $organizationId,
                        $contact,
                        '',
                        true
                    );
                    if ($result && $result->flow) {
                        return $flowExecutionService->startFlow($result->flow->id, $chat);
                    }
                    return false;

                case 'fallback_help':
                    // If the fallback trigger has a flow, start it
                    if ($fallbackTrigger && $fallbackTrigger->flow_id) {
                        $flowExecutionService = new \Modules\FlowBuilder\Services\FlowExecutionService(
                            $organizationId,
                            $this->metaPayload
                        );
                        return $flowExecutionService->startFlow($fallbackTrigger->flow_id, $chat);
                    }
                    return false;

                case 'fallback_talk_to_agent':
                    // Escalate to agent
                    Log::info('Legacy fallback: Talk to Agent requested', [
                        'organization_id' => $organizationId,
                        'contact_id' => $contact->id,
                    ]);

                    $routingService = new AgentRoutingService();
                    $routingService->assignToAgent($organizationId, $contact->id, 'auto_assignment');

                    $transferMessage = $this->botSettingsService->getSetting(
                        $organizationId,
                        'escalation_transfer_message',
                        "You're being connected to a team member who can help. Please hold on a moment."
                    );
                    $this->initializeWhatsappService($organizationId)->sendMessage(
                        $contact->uuid,
                        $transferMessage,
                        null,
                        'text'
                    );

                    return true;

                default:
                    return false;
            }
        } catch (\Throwable $e) {
            Log::error('Legacy fallback button response handler failed', [
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

        // Fallback menu button clicks (legacy — fallback menu is deprecated)
        if (strpos($buttonId, 'fallback_') === 0) {
            return $this->handleLegacyFallbackButtonResponse($organizationId, $chat, $buttonId);
        }

        // AI "Was this helpful?" button clicks
        if ($buttonId === 'ai_helpful_yes' || $buttonId === 'ai_helpful_no') {
            return $this->handleAiHelpfulButton($organizationId, $chat, $buttonId);
        }

        return null;
    }

    /**
     * Handle AI "Was this helpful?" button response.
     * Yes → acknowledge, No → check escalation or send simple response.
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

            // ai_helpful_no — user said answer was not helpful
            Log::info('AI Helpful: User said answer was not helpful', [
                'organization_id' => $organizationId,
                'contact_id' => $contact->id,
            ]);

            // Record fallback counter and check escalation threshold
            $this->rateLimitService->recordFallback($organizationId, $contact->id);
            if ($this->rateLimitService->shouldEscalate($organizationId, $contact->id)) {
                Log::info('AI Helpful: Escalation threshold exceeded, auto-assigning to agent', [
                    'organization_id' => $organizationId,
                    'contact_id' => $contact->id,
                ]);

                $routingService = new AgentRoutingService();
                $routingService->assignToAgent($organizationId, $contact->id, 'auto_assignment');

                $transferMessage = $this->botSettingsService->getSetting(
                    $organizationId,
                    'escalation_transfer_message',
                    "You're being connected to a team member who can help. Please hold on a moment."
                );
                $this->initializeWhatsappService($organizationId)->sendMessage(
                    $contact->uuid,
                    $transferMessage,
                    null,
                    'text'
                );

                return true;
            }

            // Not escalating yet — send a simple text response
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
                "I understand. Please try rephrasing your question, or type 'help' for assistance.",
                null,
                'text'
            );

            return true;
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