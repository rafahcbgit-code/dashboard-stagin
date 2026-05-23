<?php

namespace Modules\FlowBuilder\Services;

use App\Helpers\CustomHelper;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Template;
use App\Services\BotSettingsService;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowSubmission;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\ActionExecutionService;
use Modules\MetaFlows\Models\MetaFlow;
use Modules\MetaFlows\Services\MetaFlowService;

class FlowExecutionService
{
    use TemplateTrait;
    private WhatsappService $whatsappService;
    private int $organizationId;
    private $metaPayload;

    private int $logNodesProcessed = 0;

    public function __construct(int $organizationId, $metaPayload = null)
    {
        $this->organizationId = $organizationId;
        $this->metaPayload = $metaPayload;
        $this->initWhatsappService();
    }

    private function initWhatsappService(): void
    {
        $config = Organization::where('id', $this->organizationId)->value('metadata');
        $config = $config ? json_decode($config, true) : [];

        $this->whatsappService = new WhatsappService(
            $config['whatsapp']['access_token'] ?? null,
            config('graph.api_version'),
            $config['whatsapp']['app_id'] ?? null,
            $config['whatsapp']['phone_number_id'] ?? null,
            $config['whatsapp']['waba_id'] ?? null,
            $this->organizationId
        );
    }

    /**
     * Resume an active flow for a contact, or return false if none active.
     * Called by AutoReplyService when no trigger matched — only resumes existing flows.
     */
    public function resumeActiveFlow($chat, string $message): bool
    {
        if (!CustomHelper::isModuleEnabled('Flow builder', $chat->organization_id)) {
            return false;
        }

        $flowData = FlowUserData::where('contact_id', $chat->contact_id)->first();

        if (!$flowData) {
            return false;
        }

        // Check if the flow still exists
        $flow = Flow::find($flowData->flow_id);
        if (!$flow) {
            Log::info("FlowExecution: flow {$flowData->flow_id} deleted, clearing state for contact {$chat->contact_id}");
            $flowData->delete();
            return false;
        }

        // Check timeout
        $timeoutMinutes = app(BotSettingsService::class)->getFlowTimeoutMinutes($this->organizationId);
        if ($flowData->updated_at && $flowData->updated_at->diffInMinutes(now()) >= $timeoutMinutes) {
            Log::info("FlowExecution: flow timed out for contact {$chat->contact_id} after {$timeoutMinutes} min");

            // Log the timed-out flow run
            try {
                FlowLog::create([
                    'flow_id' => $flowData->flow_id,
                    'contact_id' => $chat->contact_id,
                    'organization_id' => $this->organizationId,
                    'status' => 'timed_out',
                    'nodes_processed' => 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('FlowExecution: failed to create FlowLog on timeout, continuing cleanup', [
                    'contact_id' => $chat->contact_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $flowData->delete();
            return false;
        }

        // Check if resuming on a question node — validate before continuing
        $questionConfig = $flowData->getVariable('_question_config');
        if ($questionConfig && is_array($questionConfig)) {
            return $this->handleQuestionResponse($chat, $flowData, $message, $questionConfig);
        }

        $result = $this->processFlow($chat, $flowData, strtolower(trim($message)));

        return $result !== false;
    }

    /**
     * Start a specific flow by ID. Called by TriggerEvaluationService after a trigger match.
     */
    public function startFlow(int $flowId, $chat): bool
    {
        try {
            Log::info('FlowExecution::startFlow called', [
                'flow_id' => $flowId,
                'chat_id' => $chat->id ?? null,
                'contact_id' => $chat->contact_id ?? null,
                'org_id' => $chat->organization_id ?? null,
            ]);

            $flow = Flow::where('id', $flowId)
                ->where('organization_id', $chat->organization_id)
                ->where('status', 'active')
                ->first();

            if (!$flow) {
                Log::warning('FlowExecution::startFlow - flow not found or inactive', [
                    'flow_id' => $flowId,
                    'org_id' => $chat->organization_id,
                    'searched_org_id' => $chat->organization_id ?? 'NULL',
                ]);

                // Fallback: try finding flow without org check (in case org_id is missing on chat)
                $flowFallback = Flow::where('id', $flowId)->where('status', 'active')->first();
                if ($flowFallback) {
                    Log::info('FlowExecution::startFlow - found flow without org filter, using it', [
                        'flow_id' => $flowFallback->id,
                        'flow_org_id' => $flowFallback->organization_id,
                        'chat_org_id' => $chat->organization_id ?? 'NULL',
                    ]);
                    $flow = $flowFallback;
                } else {
                    return false;
                }
            }

            // Clear any existing flow state for this contact
            FlowUserData::where('contact_id', $chat->contact_id)->delete();

            $parser = new FlowMetadataParser($flow->metadata);
            $startNodeId = $parser->getStartNodeId();

            Log::info('FlowExecution::startFlow - parsed metadata', [
                'flow_id' => $flow->id,
                'start_node_id' => $startNodeId ?? 'NULL',
            ]);

            $flowData = FlowUserData::create([
                'contact_id' => $chat->contact_id,
                'flow_id' => $flow->id,
                'current_step' => $startNodeId ?? '1',
                'variables' => [
                    '_flow_run_uuid' => (string) Str::uuid(),
                ],
            ]);

            // Log the flow run so it's counted in the Runs column
            try {
                FlowLog::create([
                    'flow_id' => $flow->id,
                    'contact_id' => $chat->contact_id,
                    'organization_id' => $this->organizationId,
                    'status' => 'started',
                    'nodes_processed' => 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('FlowExecution: failed to create FlowLog on start, continuing', [
                    'flow_id' => $flow->id,
                    'contact_id' => $chat->contact_id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('FlowExecution::startFlow - FlowUserData created', [
                'flow_data_id' => $flowData->id,
                'contact_id' => $flowData->contact_id,
                'flow_id' => $flowData->flow_id,
                'current_step' => $flowData->current_step,
            ]);

            $message = $this->extractMessageText($chat);
            Log::info('FlowExecution::startFlow - starting processFlow', [
                'message' => $message,
            ]);

            $result = $this->processFlow($chat, $flowData, '');

            Log::info('FlowExecution::startFlow - processFlow result', [
                'result' => is_string($result) ? $result : ($result === false ? 'false' : 'true'),
            ]);

            return $result !== false;
        } catch (\Throwable $e) {
            Log::error('FlowExecution::startFlow failed', [
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Log the failed flow run
            try {
                FlowLog::create([
                    'flow_id' => $flowId,
                    'contact_id' => $chat->contact_id ?? 0,
                    'organization_id' => $this->organizationId,
                    'status' => 'error',
                    'nodes_processed' => 0,
                ]);
            } catch (\Throwable $logError) {
                Log::error('FlowExecution: failed to create FlowLog on error', [
                    'flow_id' => $flowId,
                    'error' => $logError->getMessage(),
                ]);
            }

            // Clean up stale flow state to prevent resumeActiveFlow from re-processing
            FlowUserData::where('contact_id', $chat->contact_id ?? 0)->delete();
            return false;
        }
    }

    /**
     * Check if a contact has an active (non-expired) flow.
     */
    public function hasActiveFlow($chat): bool
    {
        $activeFlow = FlowUserData::where('contact_id', $chat->contact_id)->first();

        if (!$activeFlow) {
            return false;
        }

        $timeoutMinutes = app(BotSettingsService::class)->getFlowTimeoutMinutes($this->organizationId);
        if ($activeFlow->updated_at && $activeFlow->updated_at->diffInMinutes(now()) >= $timeoutMinutes) {
            // Log the timed-out flow run
            try {
                FlowLog::create([
                    'flow_id' => $activeFlow->flow_id,
                    'contact_id' => $chat->contact_id,
                    'organization_id' => $this->organizationId,
                    'status' => 'timed_out',
                    'nodes_processed' => 0,
                ]);
            } catch (\Throwable $e) {
                Log::error('FlowExecution: failed to create FlowLog on timeout, continuing cleanup', [
                    'contact_id' => $chat->contact_id,
                    'error' => $e->getMessage(),
                ]);
            }

            $activeFlow->delete();
            Log::info("FlowExecution: flow timed out for contact {$chat->contact_id}");
            return false;
        }

        return true;
    }

    /**
     * Continue a flow that was paused by a delay action.
     */
    public function continueDelayedFlow(int $contactId, int $flowId, $currentStep): bool
    {
        $flowData = FlowUserData::where('contact_id', $contactId)
            ->where('flow_id', $flowId)
            ->first();

        if (!$flowData) {
            Log::warning("FlowExecution: no FlowUserData for delayed contact {$contactId}");
            return false;
        }

        if ($flowData->current_step != $currentStep) {
            $flowData->current_step = $currentStep;
            $flowData->save();
        }

        $contact = Contact::find($contactId);
        if (!$contact) {
            return false;
        }

        $chat = (object) [
            'contact_id' => $contactId,
            'organization_id' => $this->organizationId,
        ];

        return $this->processFlow($chat, $flowData, '') !== false;
    }

    // ─── Core Processing ───────────────────────────────────────

    /**
     * Walk the flow from current_step, processing action nodes automatically
     * and stopping at message nodes. Prevents infinite loops with visited tracking.
     */
    private function processFlow($chat, FlowUserData $flowData, string $message)
    {
        $flow = Flow::find($flowData->flow_id);
        if (!$flow || empty($flow->metadata)) {
            Log::warning('FlowExecution::processFlow - flow not found or empty metadata', [
                'flow_id' => $flowData->flow_id,
            ]);
            return false;
        }

        $contact = Contact::find($chat->contact_id);
        if (!$contact) {
            Log::warning('FlowExecution::processFlow - contact not found', [
                'contact_id' => $chat->contact_id,
            ]);
            return false;
        }

        $parser = new FlowMetadataParser($flow->metadata);
        $visited = [];
        $maxSteps = 50;

        $currentNodeId = (string) $flowData->current_step;

        Log::info('FlowExecution::processFlow starting', [
            'flow_id' => $flow->id,
            'contact_id' => $chat->contact_id,
            'current_step' => $currentNodeId,
            'message' => $message,
        ]);

        $this->logNodesProcessed = 0;

        for ($i = 0; $i < $maxSteps; $i++) {
            // Cycle detection
            if (isset($visited[$currentNodeId])) {
                Log::warning("FlowExecution: cycle detected at node {$currentNodeId}, ending flow");
                $this->endFlow($chat->contact_id);
                return false;
            }
            $visited[$currentNodeId] = true;

            $node = $parser->getNodeById($currentNodeId);
            if (!$node) {
                Log::info("FlowExecution: node {$currentNodeId} not found in flow, ending flow", [
                    'available_nodes' => array_keys($parser->nodeMap ?? []),
                ]);
                $this->endFlow($chat->contact_id);
                return false;
            }

            $nodeType = $node['type'] ?? null;
            $this->logNodesProcessed++;
            Log::info("FlowExecution: processing node", [
                'step' => $i,
                'node_id' => $currentNodeId,
                'node_type' => $nodeType,
            ]);

            // Start node: skip over it to the first real node
            if ($nodeType === 'start') {
                $nextNode = $parser->getNextNode($currentNodeId);
                if (!$nextNode) {
                    Log::warning('FlowExecution: start node has no outgoing edge', [
                        'node_id' => $currentNodeId,
                    ]);
                    $this->endFlow($chat->contact_id);
                    return false;
                }
                $currentNodeId = (string) $nextNode['id'];
                $this->updateStep($chat->contact_id, $currentNodeId);
                Log::info('FlowExecution: start node → next node', ['next_id' => $currentNodeId]);
                continue;
            }

            // Action node: execute, then continue walking
            if ($nodeType === 'action') {
                $result = $this->processAction($parser, $node, $contact, $message, $flowData, $chat->contact_id);

                if ($result === false) {
                    return false;
                }
                if ($result === 'validation_failed' || $result === 'delayed') {
                    return $result;
                }

                // For conditional actions, the branch was already applied
                if ($result === 'conditional_routed') {
                    $flowData = $this->refreshFlowData($chat->contact_id);
                    $currentNodeId = (string) $flowData->current_step;
                    continue;
                }

                // Move to next node
                $nextNode = $parser->getNextNode($currentNodeId);
                if (!$nextNode) {
                    $this->endFlow($chat->contact_id);
                    return false;
                }
                $currentNodeId = (string) $nextNode['id'];
                $this->updateStep($chat->contact_id, $currentNodeId);
                continue;
            }

            // Question node: send question text, pause for user response
            if ($nodeType === 'question') {
                $fields = \Arr::get($node, 'data.metadata.fields', []);
                $questionText = $fields['question_text'] ?? '';
                $variableName = $fields['variable_name'] ?? '';

                if (empty($questionText) || empty($variableName)) {
                    Log::warning('FlowExecution: question node missing question_text or variable_name', [
                        'node_id' => $currentNodeId,
                    ]);
                    $this->endFlow($chat->contact_id);
                    return false;
                }

                $questionText = $this->replacePlaceholders($contact->uuid, $questionText, $flowData->variables ?? []);
                $this->whatsappService->sendMessage($contact->uuid, $questionText, 0, 'text');

                // Store question config for validation on resume
                $flowData->setVariable('_question_config', [
                    'node_id' => $currentNodeId,
                    'question_text' => $questionText,
                    'variable_name' => $variableName,
                    'input_type' => $fields['input_type'] ?? 'text',
                    'max_retries' => (int) ($fields['max_retries'] ?? 3),
                    'retry_message' => $fields['retry_message'] ?? '',
                    'validation_pattern' => $fields['validation_pattern'] ?? '',
                ]);
                $flowData->setVariable('_question_retries', 0);

                // Don't advance — stay on this node until the answer is validated
                Log::info('FlowExecution: question node sent, pausing for response', [
                    'node_id' => $currentNodeId,
                    'variable_name' => $variableName,
                ]);
                return true;
            }

            // Portal node: end current flow, start target flow
            if ($nodeType === 'portal') {
                $fields = \Arr::get($node, 'data.metadata.fields', []);
                $targetFlowUuid = $fields['target_flow_uuid'] ?? null;

                if (!$targetFlowUuid) {
                    Log::warning('FlowExecution: portal node missing target_flow_uuid', [
                        'node_id' => $currentNodeId,
                    ]);
                    $this->endFlow($chat->contact_id);
                    return false;
                }

                $targetFlow = Flow::where('uuid', $targetFlowUuid)
                    ->where('organization_id', $this->organizationId)
                    ->where('status', 'active')
                    ->first();

                if (!$targetFlow) {
                    Log::warning('FlowExecution: portal target flow not found or inactive', [
                        'target_flow_uuid' => $targetFlowUuid,
                        'node_id' => $currentNodeId,
                    ]);
                    $this->endFlow($chat->contact_id);
                    return false;
                }

                Log::info('FlowExecution: portal redirect', [
                    'from_flow' => $flow->id,
                    'to_flow' => $targetFlow->id,
                    'contact_id' => $chat->contact_id,
                    'node_id' => $currentNodeId,
                ]);

                $this->endFlow($chat->contact_id, 'portal_redirect');

                return $this->startFlow($targetFlow->id, $chat);
            }

            // Message node: if this is an interactive node (buttons/list/CTA) and we're
            // resuming with user input, route to the correct branch instead of resending.
            $fields = \Arr::get($node, 'data.metadata.fields', []);
            $msgType = $fields['type'] ?? null;
            $needsUserInput = in_array($msgType, ['interactive buttons', 'interactive list', 'interactive call to action url']);

            if ($needsUserInput && !empty($message)) {
                // User clicked a button/list item — route to the matching branch
                $nextNode = $parser->getNextNode($currentNodeId, $message);
                if ($nextNode) {
                    $this->updateStep($chat->contact_id, (string) $nextNode['id']);
                    $currentNodeId = (string) $nextNode['id'];
                    Log::info('FlowExecution: interactive input routed', [
                        'from_node' => $currentNodeId,
                        'message' => $message,
                        'next_node' => $currentNodeId,
                    ]);
                    $flowData = $this->refreshFlowData($chat->contact_id);
                    continue;
                }
                // No matching branch found — end flow or fall through
                Log::warning('FlowExecution: no matching branch for interactive input', [
                    'node_id' => $currentNodeId,
                    'message' => $message,
                ]);
                $this->endFlow($chat->contact_id);
                return false;
            }

            // Send the message (first visit to this node, no user input yet)
            $sent = $this->sendNodeMessage($node, $contact, $flowData->variables ?? []);

            Log::info('FlowExecution: message node send result', [
                'node_id' => $currentNodeId,
                'msg_type' => $msgType,
                'needs_user_input' => $needsUserInput,
                'sent' => $sent ? 'true' : 'false',
            ]);

            if ($needsUserInput) {
                // Interactive node: stay on this node and wait for user response.
                // Do NOT advance — getNextNode can't resolve multi-edge nodes
                // without a button/list selection, and advancing would end the flow.
                return true;
            }

            // Plain text/media: send and advance to next node
            $this->advanceToNextStep($parser, $currentNodeId, $chat->contact_id);
            $flowData = $this->refreshFlowData($chat->contact_id);
            if (!$flowData) {
                // Flow was completed (no more steps) — endFlow already called by advanceToNextStep
                return true;
            }
            $currentNodeId = (string) $flowData->current_step;
            continue;
        }

        Log::warning("FlowExecution: max iterations reached for contact {$chat->contact_id}");
        $this->endFlow($chat->contact_id);
        return false;
    }

    // ─── Action Processing ──────────────────────────────────────

    private function processAction(FlowMetadataParser $parser, array $node, Contact $contact, string $message, FlowUserData $flowData, int $contactId)
    {
        $actionType = \Arr::get($node, 'data.actionType');
        $config = \Arr::get($node, 'data.config', []);
        $isActive = \Arr::get($node, 'data.is_active', true);

        if (!$actionType || !$isActive) {
            return true; // Skip inactive/empty actions
        }

        $actionType = str_replace('-', '_', $actionType);
        $actionService = new ActionExecutionService($this->organizationId, $this->metaPayload);
        $result = $actionService->executeAction($actionType, $config, $contact, $message, $flowData, $contactId);

        // Conditional actions need special routing
        if ($actionType === 'conditional') {
            return $this->routeConditional($parser, $node, $result, $contactId);
        }

        // Update contact validation failure
        if ($actionType === 'update_contact' && $result === false) {
            return 'validation_failed';
        }

        if ($result === false) {
            Log::warning("FlowExecution: action {$actionType} failed for contact {$contactId}");
            $this->endFlow($contactId);
            return false;
        }

        if ($result === 'delayed') {
            return 'delayed';
        }

        return true;
    }

    private function routeConditional(FlowMetadataParser $parser, array $node, $conditionResult, int $contactId): string
    {
        $nodeId = (string) $node['id'];

        // Ensure current_step is set to this conditional node
        $flowData = FlowUserData::where('contact_id', $contactId)->first();
        if ($flowData && $flowData->current_step != $nodeId) {
            $flowData->current_step = $nodeId;
            $flowData->save();
        }

        // Build handle for the matched condition or default
        $handle = ($conditionResult !== 'default')
            ? 'condition-' . $conditionResult . '|' . $nodeId
            : 'default|' . $nodeId;

        $nextNode = $parser->getNextNode($nodeId, '', $handle);

        if ($nextNode) {
            $this->updateStep($contactId, (string) $nextNode['id']);
            return 'conditional_routed';
        }

        Log::warning("FlowExecution: no conditional path for handle {$handle}");
        $this->endFlow($contactId);
        return false;
    }

    // ─── Message Sending ────────────────────────────────────────

    private function sendNodeMessage(array $node, Contact $contact, array $flowVariables = []): bool
    {
        $fields = \Arr::get($node, 'data.metadata.fields', []);
        $type = $fields['type'] ?? null;

        Log::info('FlowExecution::sendNodeMessage', [
            'node_id' => $node['id'] ?? 'unknown',
            'node_type' => $node['type'] ?? 'unknown',
            'message_type' => $type,
            'contact_uuid' => $contact->uuid,
            'has_body' => !empty($fields['body']),
        ]);

        if (!$type) {
            Log::warning('FlowExecution::sendNodeMessage - no message type found', [
                'node_id' => $node['id'] ?? 'unknown',
                'fields_keys' => array_keys($fields),
            ]);
            return false;
        }

        $message = $this->replacePlaceholders($contact->uuid, $fields['body'] ?? '', $flowVariables);
        $header = $this->prepareHeader($fields);

        switch ($type) {
            case 'text':
                $response = $this->whatsappService->sendMessage($contact->uuid, $message, 0, 'text');
                break;

            case 'media':
                $mediaInfo = json_decode(($fields['media']['metadata'] ?? '{}'), true);
                $mediaLocation = ($fields['media']['location'] ?? '') === 'aws' ? 'amazon' : ($fields['media']['location'] ?? '');
                $response = $this->whatsappService->sendMedia(
                    $contact->uuid,
                    $fields['mediaType'] ?? '',
                    $mediaInfo['name'] ?? '',
                    $fields['media']['path'] ?? '',
                    $fields['media']['path'] ?? '',
                    $mediaLocation,
                    $fields['caption'] ?? ''
                );
                break;

            case 'interactive buttons':
                $buttonType = ($fields['buttonType'] ?? '') === 'buttons'
                    ? 'interactive buttons'
                    : 'interactive call to action url';
                $buttons = $this->prepareButtons($fields, $buttonType);
                $response = $this->whatsappService->sendMessage(
                    $contact->uuid, $message, 0, $buttonType, $buttons, $header, $fields['footer'] ?? ''
                );
                break;

            case 'interactive list':
                $buttons = $this->prepareButtons($fields, 'interactive list');
                $response = $this->whatsappService->sendMessage(
                    $contact->uuid, $message, 0, 'interactive list', $buttons, $header,
                    $fields['footer'] ?? '', $fields['buttonLabel'] ?? ''
                );
                break;

            case 'template':
                $response = $this->sendTemplateMessage($fields, $contact, $flowVariables);
                break;

            case 'metaflow':
                $response = $this->sendMetaFlowMessage($fields, $contact, $flowVariables);
                break;

            default:
                return false;
        }

        return $response !== null && $response !== false;
    }

    private function prepareHeader(array $fields): array
    {
        $header = [];

        if (($fields['headerType'] ?? '') === 'text') {
            $header = ['type' => 'text', 'text' => clean($fields['headerText'] ?? '')];
        } elseif (($fields['headerType'] ?? '') !== 'none') {
            $type = $fields['headerType'] ?? '';
            $header['type'] = $type;
            $header[$type] = ['link' => $fields['headerMedia']['path'] ?? ''];
        }

        return $header;
    }

    private function prepareButtons(array $fields, string $type): array
    {
        if ($type === 'interactive buttons') {
            $buttons = [];
            foreach ($fields['buttons'] ?? [] as $id => $title) {
                if (!empty($title)) {
                    $buttons[] = ['id' => $id, 'title' => $title];
                }
            }
            return $buttons;
        }

        if ($type === 'interactive call to action url') {
            return [
                'display_text' => $fields['ctaUrlButton']['displayText'] ?? '',
                'url' => $fields['ctaUrlButton']['url'] ?? '',
            ];
        }

        if ($type === 'interactive list') {
            return collect($fields['sections'] ?? [])->map(function ($section) {
                return [
                    'title' => $section['title'] ?? '',
                    'rows' => collect($section['rows'] ?? [])->map(function ($row) {
                        return [
                            'id' => $row['id'] ?? '',
                            'title' => $row['title'] ?? '',
                            'description' => $row['description'] ?? '',
                        ];
                    })->all(),
                ];
            })->all();
        }

        return [];
    }

    /**
     * Send a WhatsApp template message from a flow node.
     */
    private function sendTemplateMessage(array $fields, Contact $contact, array $flowVariables)
    {
        $templateUuid = $fields['template_uuid'] ?? null;
        if (!$templateUuid) {
            Log::warning('FlowExecution: template node missing template_uuid');
            return false;
        }

        $template = Template::where('uuid', $templateUuid)
            ->where('organization_id', $this->organizationId)
            ->where('status', 'APPROVED')
            ->first();

        if (!$template) {
            Log::warning('FlowExecution: template not found or not approved', [
                'template_uuid' => $templateUuid,
                'organization_id' => $this->organizationId,
            ]);
            return false;
        }

        // Build metadata object that TemplateTrait::buildTemplate() expects
        $parameters = $fields['parameters'] ?? [];
        $metadata = $this->buildTemplateMetadataFromConfig($parameters, $template, $contact, $flowVariables);

        try {
            $templateContent = $this->buildTemplate(
                $template->name,
                $template->language,
                $metadata,
                $contact
            );

            $response = $this->whatsappService->sendTemplateMessage(
                $contact->uuid,
                $templateContent,
                null,
                null,
                null,
                $contact,
                null,
                $template
            );

            return $response !== null && $response !== false;
        } catch (\Throwable $e) {
            Log::error('FlowExecution: failed to send template message', [
                'template_uuid' => $templateUuid,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a WhatsApp Flow (MetaFlow) message from a flow node.
     */
    private function sendMetaFlowMessage(array $fields, Contact $contact, array $flowVariables)
    {
        $metaflowUuid = $fields['metaflow_uuid'] ?? null;
        if (!$metaflowUuid) {
            Log::warning('FlowExecution: metaflow node missing metaflow_uuid');
            return false;
        }

        $metaFlow = MetaFlow::where('uuid', $metaflowUuid)
            ->where('organization_id', $this->organizationId)
            ->where('status', 'published')
            ->first();

        if (!$metaFlow) {
            Log::warning('FlowExecution: metaflow not found or not published', [
                'metaflow_uuid' => $metaflowUuid,
                'organization_id' => $this->organizationId,
            ]);
            return false;
        }

        $body = $this->replacePlaceholders($contact->uuid, $fields['body'] ?? 'Please complete the form.', $flowVariables);
        $cta = $fields['cta'] ?? 'Open';

        $options = [
            'body'   => $body,
            'cta'    => $cta,
            'header' => $metaFlow->name,
        ];

        try {
            $metaFlowService = MetaFlowService::fromOrganization($this->organizationId);
            $sendResult = $metaFlowService->sendFlowToContact($metaFlow, $contact, $options);

            if (!$sendResult['success']) {
                Log::error('FlowExecution: metaflow send failed', [
                    'metaflow_uuid' => $metaflowUuid,
                    'contact_id' => $contact->id,
                    'error' => $sendResult['message'] ?? 'Unknown error',
                ]);
                return false;
            }

            // Create a Chat record for rendering in the chat thread
            $chatMetadata = [
                'type'        => 'interactive',
                'flow_name'   => $metaFlow->name,
                'flow_uuid'   => $metaFlow->uuid,
                'flow_token'  => $sendResult['flow_token'],
                'interactive' => [
                    'type' => 'flow',
                    'body' => ['text' => $body],
                    'action' => [
                        'name'       => 'flow',
                        'parameters' => [
                            'flow_id'  => $metaFlow->meta_flow_id,
                            'flow_cta' => $cta,
                        ],
                    ],
                ],
            ];

            Chat::create([
                'organization_id' => $this->organizationId,
                'wam_id'          => $sendResult['data']->messages[0]->id ?? null,
                'contact_id'      => $contact->id,
                'type'            => 'outbound',
                'user_id'         => null,
                'metadata'        => json_encode($chatMetadata),
                'status'          => 'delivered',
                'created_at'      => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('FlowExecution: failed to send metaflow message', [
                'metaflow_uuid' => $metaflowUuid,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build the metadata object for TemplateTrait::buildTemplate()
     * from the node config parameters and the template's stored metadata.
     */
    private function buildTemplateMetadataFromConfig(array $parameters, Template $template, Contact $contact, array $flowVariables): object
    {
        $templateMetadata = is_string($template->metadata)
            ? json_decode($template->metadata, true)
            : (is_array($template->metadata) ? $template->metadata : []);

        $components = $templateMetadata['components'] ?? [];

        // Parse components into header/body/footer/buttons
        $header = null;
        $bodyText = null;
        $footer = null;
        $buttons = [];

        foreach ($components as $component) {
            $type = $component['type'] ?? '';
            if ($type === 'HEADER') {
                $header = [
                    'format' => $component['format'] ?? 'TEXT',
                    'text' => $component['text'] ?? '',
                    'parameters' => [],
                ];
            } elseif ($type === 'BODY') {
                $bodyText = $component['text'] ?? '';
            } elseif ($type === 'FOOTER') {
                $footer = ['text' => $component['text'] ?? ''];
            } elseif ($type === 'BUTTONS') {
                foreach ($component['buttons'] ?? [] as $idx => $btn) {
                    $buttons[] = [
                        'type' => $btn['type'] ?? 'QUICK_REPLY',
                        'text' => $btn['text'] ?? '',
                        'parameters' => [],
                    ];
                    if (($btn['type'] ?? '') === 'URL' && !empty($btn['url'])) {
                        $buttons[count($buttons) - 1]['url'] = $btn['url'];
                    }
                }
            }
        }

        // Fill header parameters from node config
        $headerParams = $parameters['header'] ?? [];
        if ($header && !empty($headerParams)) {
            foreach ($headerParams as $param) {
                $value = $this->replacePlaceholders($contact->uuid, $param['value'] ?? '', $flowVariables);
                $header['parameters'][] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => $value,
                ];
            }
        } elseif ($header && $header['format'] === 'TEXT') {
            // Auto-extract placeholder count from header text
            preg_match_all('/\{\{(\d+)\}\}/', $header['text'], $matches);
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $header['parameters'][] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => $headerParams[$i]['value'] ?? 'N/A',
                ];
            }
        }

        // Fill body parameters from node config
        $bodyParams = $parameters['body'] ?? [];
        if (!empty($bodyParams)) {
            foreach ($bodyParams as $param) {
                $value = $this->replacePlaceholders($contact->uuid, $param['value'] ?? '', $flowVariables);
                $bodyParamsResolved[] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => $value,
                ];
            }
        } else {
            // Auto-extract from body text
            $bodyParamsResolved = [];
            preg_match_all('/\{\{(\d+)\}\}/', $bodyText ?? '', $matches);
            for ($i = 0; $i < count($matches[0]); $i++) {
                $bodyParamsResolved[] = [
                    'type' => 'text',
                    'selection' => 'static',
                    'value' => 'N/A',
                ];
            }
        }

        $metadata = [
            'header' => $header,
            'body' => [
                'text' => $bodyText ?? '',
                'parameters' => $bodyParamsResolved ?? [],
            ],
            'footer' => $footer,
            'buttons' => $buttons,
        ];

        return json_decode(json_encode($metadata));
    }

    // ─── Flow State ─────────────────────────────────────────────

    private function advanceToNextStep(FlowMetadataParser $parser, string $currentNodeId, int $contactId): void
    {
        $nextNode = $parser->getNextNode($currentNodeId);

        if ($nextNode && isset($nextNode['id'])) {
            $this->updateStep($contactId, (string) $nextNode['id']);
        } else {
            Log::info('FlowExecution: flow completed naturally (no more nodes)', [
                'contact_id' => $contactId,
                'last_node' => $currentNodeId,
            ]);
            $this->endFlow($contactId);
        }
    }

    private function updateStep(int $contactId, string $step): void
    {
        FlowUserData::where('contact_id', $contactId)->update(['current_step' => $step]);
    }

    private function endFlow(int $contactId, string $reason = 'completed'): void
    {
        $flowData = FlowUserData::where('contact_id', $contactId)->first();
        if ($flowData) {
            try {
                FlowLog::create([
                    'flow_id' => $flowData->flow_id,
                    'contact_id' => $contactId,
                    'organization_id' => $this->organizationId,
                    'status' => $reason,
                    'nodes_processed' => $this->logNodesProcessed,
                ]);
            } catch (\Throwable $e) {
                Log::error('FlowExecution: failed to create FlowLog, continuing cleanup', [
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        FlowUserData::where('contact_id', $contactId)->delete();
    }

    private function refreshFlowData(int $contactId): ?FlowUserData
    {
        return FlowUserData::where('contact_id', $contactId)->first();
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function extractMessageText($chat): string
    {
        $metadata = json_decode($chat->metadata, true);
        $type = $metadata['type'] ?? null;

        if ($type === 'text') {
            return $metadata['text']['body'] ?? '';
        }
        if ($type === 'button') {
            return $metadata['button']['payload'] ?? '';
        }
        if ($type === 'interactive') {
            $interactiveType = $metadata['interactive']['type'] ?? null;
            if ($interactiveType === 'button_reply') {
                return $metadata['interactive']['button_reply']['title'] ?? '';
            }
            if ($interactiveType === 'list_reply') {
                return $metadata['interactive']['list_reply']['title'] ?? '';
            }
        }

        return '';
    }

    /**
     * Replace placeholder variables in a message string.
     * Supports three syntaxes:
     *   {first_name}         — legacy flat contact fields (backward compat)
     *   {{contact.first_name}} — dot-notation contact variables
     *   {{flow.order_id}}    — flow-scoped session variables
     *   {{system.current_date}} — read-only system variables
     */
    private function replacePlaceholders(string $contactUuid, string $message, ?array $flowVariables = null): string
    {
        $organization = Organization::where('id', $this->organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();

        if (!$contact) {
            return $message;
        }

        $address = $contact->address ? json_decode($contact->address, true) : [];
        $contactMetadata = $contact->metadata ? json_decode($contact->metadata, true) : [];

        $fullAddress = collect([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ])->filter()->implode(', ');

        // Build contact data map (used by both {flat} and {{contact.*}})
        $contactData = [
            'first_name' => $contact->first_name ?? '',
            'last_name' => $contact->last_name ?? '',
            'full_name' => $contact->full_name ?? '',
            'email' => $contact->email ?? '',
            'phone' => $contact->phone ?? '',
            'organization_name' => $organization->name ?? '',
            'full_address' => $fullAddress,
            'street' => $address['street'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'zip_code' => $address['zip'] ?? '',
            'country' => $address['country'] ?? '',
        ];

        // Merge contact metadata
        if ($contactMetadata) {
            foreach ($contactMetadata as $key => $value) {
                $contactData[strtolower(str_replace(' ', '_', $key))] = $value;
            }
        }

        // System variables
        $systemData = [
            'current_date' => now()->format('Y-m-d'),
            'current_time' => now()->format('H:i'),
            'organization_name' => $organization->name ?? '',
        ];

        // Flow variables (from flow_user_data.variables)
        $flowData = $flowVariables ?? [];

        // Replace {{system.*}} variables
        $message = preg_replace_callback('/\{\{system\.(\w+)\}\}/', function ($m) use ($systemData) {
            return $systemData[$m[1]] ?? $m[0];
        }, $message);

        // Replace {{flow.*}} variables
        $message = preg_replace_callback('/\{\{flow\.(\w+)\}\}/', function ($m) use ($flowData) {
            return $flowData[$m[1]] ?? $m[0];
        }, $message);

        // Replace {{contact.*}} variables
        $message = preg_replace_callback('/\{\{contact\.(\w+)\}\}/', function ($m) use ($contactData) {
            return $contactData[$m[1]] ?? $m[0];
        }, $message);

        // Replace legacy {flat} variables (backward compat)
        $message = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($contactData) {
            return $contactData[$m[1]] ?? $m[0];
        }, $message);

        return $message;
    }

    // ─── Question Node Handling ────────────────────────────────────

    /**
     * Handle a user's response to a question node.
     * Validates the input, saves to variables + submissions on success,
     * retries or routes to fail path on failure.
     */
    private function handleQuestionResponse($chat, FlowUserData $flowData, string $message, array $config): bool
    {
        $contact = Contact::find($chat->contact_id);
        if (!$contact) {
            return false;
        }

        $nodeId = $config['node_id'];
        $questionText = $config['question_text'] ?? '';
        $variableName = $config['variable_name'];
        $inputType = $config['input_type'] ?? 'text';
        $maxRetries = $config['max_retries'] ?? 3;
        $retryMessage = $config['retry_message'] ?? '';
        $validationPattern = $config['validation_pattern'] ?? '';

        $isValid = $this->validateQuestionInput($message, $inputType, $validationPattern);

        Log::info('FlowExecution::handleQuestionResponse', [
            'contact_id' => $chat->contact_id,
            'node_id' => $nodeId,
            'variable_name' => $variableName,
            'input_type' => $inputType,
            'is_valid' => $isValid,
            'message_length' => strlen($message),
        ]);

        if ($isValid) {
            // Save answer to flow variables
            $vars = $flowData->variables ?? [];
            $vars[$variableName] = $message;
            unset($vars['_question_config'], $vars['_question_retries']);
            $flowData->variables = $vars;
            $flowData->save();

            // Save to persistent submissions for org export
            try {
                FlowSubmission::create([
                    'organization_id' => $this->organizationId,
                    'flow_id' => $flowData->flow_id,
                    'contact_id' => $chat->contact_id,
                    'run_uuid' => $flowData->getVariable('_flow_run_uuid') ?? (string) Str::uuid(),
                    'node_id' => $nodeId,
                    'variable_name' => $variableName,
                    'question_text' => $questionText,
                    'input_type' => $inputType,
                    'answer_value' => $message,
                    'status' => 'answered',
                    'answered_at' => now(),
                    'submission_data' => [
                        'variable_name' => $variableName,
                        'input_type' => $inputType,
                        'value' => $message,
                        'node_id' => $nodeId,
                        'question_text' => $questionText,
                        'run_uuid' => $flowData->getVariable('_flow_run_uuid'),
                        'status' => 'answered',
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::error('FlowExecution: failed to save FlowSubmission', [
                    'error' => $e->getMessage(),
                ]);
                // Don't block the flow — submission is for export, not execution
            }

            // Advance past the question node and continue processing
            $flow = Flow::find($flowData->flow_id);
            if (!$flow) {
                $this->endFlow($chat->contact_id);
                return false;
            }

            $parser = new FlowMetadataParser($flow->metadata);
            $successHandle = 'success|' . $nodeId;
            $nextNode = $parser->getNextNode($nodeId, '', $successHandle);

            // Fallback: if no success handle edge found, try default (no handle) edge
            if (!$nextNode) {
                $nextNode = $parser->getNextNode($nodeId);
            }

            if ($nextNode) {
                $this->updateStep($chat->contact_id, (string) $nextNode['id']);
                $flowData = $this->refreshFlowData($chat->contact_id);
                if (!$flowData) {
                    return true;
                }
                $result = $this->processFlow($chat, $flowData, '');
                return $result !== false;
            }

            // No next node — flow complete
            $this->endFlow($chat->contact_id);
            return true;
        }

        // Invalid input — handle retry
        $currentRetries = ($flowData->getVariable('_question_retries') ?? 0) + 1;

        Log::info('FlowExecution: question validation failed, retry', [
            'contact_id' => $chat->contact_id,
            'node_id' => $nodeId,
            'retry_attempt' => $currentRetries,
            'max_retries' => $maxRetries,
        ]);

        if ($currentRetries >= $maxRetries) {
            $this->storeQuestionAttempt($chat, $flowData, $config, $message, 'max_retries_exceeded', $currentRetries);

            // Max retries reached — look for fail route or end flow
            $flow = Flow::find($flowData->flow_id);
            if (!$flow) {
                $this->endFlow($chat->contact_id);
                return false;
            }

            $parser = new FlowMetadataParser($flow->metadata);
            $failHandle = 'fail|' . $nodeId;
            $failNode = $parser->getNextNode($nodeId, '', $failHandle);

            if ($failNode) {
                // Route to fail path
                $vars = $flowData->variables ?? [];
                unset($vars['_question_config'], $vars['_question_retries']);
                $flowData->variables = $vars;
                $flowData->save();

                $this->updateStep($chat->contact_id, (string) $failNode['id']);
                $flowData = $this->refreshFlowData($chat->contact_id);
                if (!$flowData) {
                    return true;
                }
                $result = $this->processFlow($chat, $flowData, $message);
                return $result !== false;
            }

            // No fail route — end flow
            Log::info('FlowExecution: question max retries reached, no fail route, ending flow', [
                'contact_id' => $chat->contact_id,
            ]);
            $this->endFlow($chat->contact_id);
            return false;
        }

        // Send retry message and stay paused
        $flowData->setVariable('_question_retries', $currentRetries);
        $this->storeQuestionAttempt($chat, $flowData, $config, $message, 'invalid', $currentRetries);

        if (!empty($retryMessage)) {
            $retryText = $this->replacePlaceholders($contact->uuid, $retryMessage, $flowData->variables ?? []);
            $this->whatsappService->sendMessage($contact->uuid, $retryText, 0, 'text');
        }

        return true;
    }

    private function storeQuestionAttempt($chat, FlowUserData $flowData, array $config, string $message, string $status, int $attempt): void
    {
        try {
            FlowSubmission::create([
                'organization_id' => $this->organizationId,
                'flow_id' => $flowData->flow_id,
                'contact_id' => $chat->contact_id,
                'run_uuid' => $flowData->getVariable('_flow_run_uuid') ?? (string) Str::uuid(),
                'node_id' => $config['node_id'] ?? null,
                'variable_name' => $config['variable_name'] ?? null,
                'question_text' => $config['question_text'] ?? null,
                'input_type' => $config['input_type'] ?? 'text',
                'answer_value' => $message,
                'status' => $status,
                'answered_at' => now(),
                'submission_data' => [
                    'variable_name' => $config['variable_name'] ?? null,
                    'input_type' => $config['input_type'] ?? 'text',
                    'value' => $message,
                    'node_id' => $config['node_id'] ?? null,
                    'question_text' => $config['question_text'] ?? null,
                    'run_uuid' => $flowData->getVariable('_flow_run_uuid'),
                    'status' => $status,
                    'attempt' => $attempt,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('FlowExecution: failed to save question attempt', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate user input against the question's input type.
     */
    private function validateQuestionInput(string $input, string $inputType, string $pattern = ''): bool
    {
        $input = trim($input);

        if ($input === '') {
            return false;
        }

        return match ($inputType) {
            'text'   => true,
            'email'  => filter_var($input, FILTER_VALIDATE_EMAIL) !== false,
            'phone'  => preg_match('/^\+?[0-9\s\-\(\)]{7,15}$/', $input) === 1,
            'number'  => is_numeric($input),
            'date'   => (bool) strtotime($input),
            'regex'  => $pattern ? (preg_match('/' . $pattern . '/', $input) === 1) : true,
            default  => true,
        };
    }
}
