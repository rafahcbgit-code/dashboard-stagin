<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\TriggerGroup;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Services\FlowMetadataParser;
use Illuminate\Support\Facades\Cache;

class AutomationSimulatorService
{
    protected TriggerEvaluationService $triggerEvalService;
    protected WorkingHoursService $workingHoursService;
    protected BotSettingsService $botSettings;
    protected AiSettingsService $aiSettingsService;
    protected AiFallbackService $aiFallbackService;
    protected AiEscalationInstructionService $escalationService;

    public function __construct(
        TriggerEvaluationService $triggerEvalService,
        WorkingHoursService $workingHoursService,
        BotSettingsService $botSettings,
        AiSettingsService $aiSettingsService,
        AiFallbackService $aiFallbackService,
        AiEscalationInstructionService $escalationService
    ) {
        $this->triggerEvalService = $triggerEvalService;
        $this->workingHoursService = $workingHoursService;
        $this->botSettings = $botSettings;
        $this->aiSettingsService = $aiSettingsService;
        $this->aiFallbackService = $aiFallbackService;
        $this->escalationService = $escalationService;
    }

    /**
     * Simulate what happens when a message comes in.
     * Uses the real TriggerEvaluationService for accurate matching,
     * then falls back to AI if no trigger matches.
     */
    public function simulateTriggerEvaluation(int $organizationId, string $message, bool $isNewContact, bool $duringWorkingHours): array
    {
        // Create a virtual contact — rate limits pass since id=0
        $virtualContact = new Contact();
        $virtualContact->id = 0;
        $virtualContact->organization_id = $organizationId;

        // Determine effective working hours based on toggle
        $actualWorkingHours = $this->workingHoursService->isWithinWorkingHours($organizationId);
        $effectiveWorkingHours = $duringWorkingHours;
        $workingHoursOverridden = ($duringWorkingHours !== $actualWorkingHours);

        // Build debug trace by checking each trigger manually
        // This uses the effective (simulated) working hours so the trace
        // accurately reflects what would happen under the simulated conditions
        $triggerGroups = TriggerGroup::forOrganization($organizationId)
            ->enabled()
            ->orderedByPriority()
            ->get();

        $trace = [];
        $firstMatchedTrigger = null;
        $cachedEvaluateResult = null;
        $evaluateCalled = false;

        // Fallback triggers evaluate last, same as TriggerEvaluationService
        $nonFallback = $triggerGroups->filter(fn($tg) => $tg->type !== 'fallback');
        $fallbacks = $triggerGroups->filter(fn($tg) => $tg->type === 'fallback');
        $orderedGroups = $nonFallback->merge($fallbacks);

        foreach ($orderedGroups as $tg) {
            $entry = [
                'name' => $tg->name,
                'type' => $tg->type,
                'matched' => false,
                'reason' => '',
            ];

            $matched = $this->evaluateTriggerForTrace($tg, $message, $isNewContact, $effectiveWorkingHours, $organizationId, $virtualContact, $cachedEvaluateResult, $evaluateCalled);

            if ($matched !== null) {
                $entry['matched'] = true;
                $entry['reason'] = $matched['reason'];
                if (isset($matched['matched_keyword'])) {
                    $entry['matched_keyword'] = $matched['matched_keyword'];
                }

                // First match wins (same priority logic as evaluate())
                if ($firstMatchedTrigger === null) {
                    $firstMatchedTrigger = $tg;
                    // Cache the AI evaluate result for reuse
                    if (isset($matched['evaluate_result'])) {
                        $cachedEvaluateResult = $matched['evaluate_result'];
                        $evaluateCalled = true;
                    }
                }
            } else {
                $entry['reason'] = $this->getTriggerFailReason($tg, $message, $isNewContact, $effectiveWorkingHours, $organizationId);
            }

            $trace[] = $entry;
        }

        // Build result based on first matched trigger
        $trigger = null;
        $flow = null;
        $whatsapp = null;

        if ($firstMatchedTrigger !== null) {
            $trigger = [
                'name' => $firstMatchedTrigger->name,
                'type' => $firstMatchedTrigger->type,
            ];

            // For AI-mode keyword triggers, use the cached evaluate() result
            if ($firstMatchedTrigger->type === 'new_message' && ($firstMatchedTrigger->match_mode ?? '') === 'ai' && $cachedEvaluateResult !== null) {
                if ($cachedEvaluateResult->matchedKeyword) {
                    $trigger['matched_keyword'] = $cachedEvaluateResult->matchedKeyword;
                }
            }

            $matchedFlow = $firstMatchedTrigger->flow;
            if ($matchedFlow) {
                $flow = [
                    'id' => $matchedFlow->id,
                    'name' => $matchedFlow->name,
                    'uuid' => $matchedFlow->uuid,
                ];
            }

            $flowName = $matchedFlow ? $matchedFlow->name : 'No flow linked';
            $whatsapp = [
                'type' => 'text',
                'body' => "Trigger matched: \"{$firstMatchedTrigger->name}\" — Starting flow: \"{$flowName}\"",
            ];
        }

        // If no trigger matched, try AI fallback
        $aiResult = null;
        if ($firstMatchedTrigger === null) {
            $aiEnabled = $this->aiSettingsService->isEnabled($organizationId);
            $aiFallbackEnabled = (bool) $this->botSettings->getSetting($organizationId, 'ai_fallback_enabled', false);

            if ($aiEnabled && $aiFallbackEnabled) {
                $aiResult = $this->aiFallbackService->attemptAnswer($organizationId, $message, $virtualContact);

                if ($aiResult !== null) {
                    if ($aiResult['type'] === 'escalation') {
                        $instruction = $aiResult['instruction'] ?? null;
                        $whatsapp = [
                            'type' => 'text',
                            'body' => $instruction ? $instruction->instruction : 'This would be escalated to a human agent.',
                        ];
                    } else {
                        $whatsapp = [
                            'type' => 'text',
                            'body' => $aiResult['content'] ?? 'No answer found.',
                        ];
                    }
                } else {
                    $whatsapp = [
                        'type' => 'text',
                        'body' => 'No trigger matched and AI couldn\'t find an answer.',
                    ];
                }
            } else {
                $whatsapp = [
                    'type' => 'text',
                    'body' => 'No trigger matched this message.',
                ];
            }
        }

        return [
            'matched' => $firstMatchedTrigger !== null,
            'trigger' => $trigger,
            'flow' => $flow,
            'trace' => $trace,
            'whatsapp' => $whatsapp,
            'working_hours_overridden' => $workingHoursOverridden,
            'ai_result' => $aiResult ? [
                'type' => $aiResult['type'],
                'content' => $aiResult['content'] ?? null,
            ] : null,
        ];
    }

    /**
     * Evaluate a single trigger for the debug trace.
     * Returns match info array if matched, null if not.
     */
    protected function evaluateTriggerForTrace(TriggerGroup $tg, string $message, bool $isNewContact, bool $effectiveWorkingHours, int $organizationId, $contact, &$cachedEvaluateResult = null, bool &$evaluateCalled = false): ?array
    {
        switch ($tg->type) {
            case 'new_contact':
                if (!$isNewContact) return null;
                if (!$tg->hasFlowLinked()) return null;
                return ['reason' => 'New contact condition met'];

            case 'greeting':
                if (!$effectiveWorkingHours) return null;
                if (!$tg->hasFlowLinked()) return null;
                return ['reason' => 'Within working hours'];

            case 'away':
                if ($effectiveWorkingHours) return null;
                if (!$tg->hasFlowLinked()) return null;
                return ['reason' => 'Outside working hours'];

            case 'fallback':
                if (!$tg->hasFlowLinked()) return null;
                return ['reason' => 'Fallback always matches (evaluated last)'];

            case 'new_message':
                if (!$tg->hasKeywords()) return null;
                if (!$tg->hasFlowLinked()) return null;
                if (($tg->match_mode ?? '') === 'ai' && !$this->aiSettingsService->isEnabled($organizationId)) return null;

                $keywords = $tg->keywords ?? [];
                $matchMode = $tg->match_mode ?? 'exact';
                $msg = mb_strtolower(trim($message), 'UTF-8');

                if ($matchMode === 'exact') {
                    foreach ($keywords as $keyword) {
                        $kw = mb_strtolower(trim($keyword), 'UTF-8');
                        if ($msg === $kw) {
                            return ['reason' => "Keyword matched: '{$keyword}' (exact)", 'matched_keyword' => $keyword];
                        }
                    }
                    return null;
                }

                if ($matchMode === 'contains') {
                    foreach ($keywords as $keyword) {
                        $kw = mb_strtolower(trim($keyword), 'UTF-8');
                        if (str_contains($msg, $kw)) {
                            return ['reason' => "Keyword matched: '{$keyword}' (contains)", 'matched_keyword' => $keyword];
                        }
                    }
                    return null;
                }

                if ($matchMode === 'ai') {
                    // Delegate to evaluate() for AI keyword classification (cache the result)
                    if (!$evaluateCalled) {
                        $cachedEvaluateResult = $this->triggerEvalService->evaluate($organizationId, $contact, $message, $isNewContact);
                        $evaluateCalled = true;
                    }
                    if ($cachedEvaluateResult !== null && $cachedEvaluateResult->triggerGroup->id === $tg->id) {
                        return ['reason' => 'AI classified this message', 'matched_keyword' => $cachedEvaluateResult->matchedKeyword, 'evaluate_result' => $cachedEvaluateResult];
                    }
                    return null;
                }

                return null;

            default:
                return null;
        }
    }

    /**
     * Get the reason a trigger did NOT match, for the debug trace.
     */
    protected function getTriggerFailReason(TriggerGroup $tg, string $message, bool $isNewContact, bool $effectiveWorkingHours, int $organizationId): string
    {
        switch ($tg->type) {
            case 'new_contact':
                if (!$isNewContact) return 'Contact is not new';
                if (!$tg->hasFlowLinked()) return 'No flow linked';
                return 'Unknown reason';

            case 'greeting':
                if (!$effectiveWorkingHours) return 'Outside working hours';
                if (!$tg->hasFlowLinked()) return 'No flow linked';
                return 'Unknown reason';

            case 'away':
                if ($effectiveWorkingHours) return 'Within working hours (away only fires outside)';
                if (!$tg->hasFlowLinked()) return 'No flow linked';
                return 'Unknown reason';

            case 'fallback':
                if (!$tg->hasFlowLinked()) return 'No flow linked';
                return 'Unknown reason';

            case 'new_message':
                if (!$tg->hasKeywords()) return 'No keywords configured';
                if (!$tg->hasFlowLinked()) return 'No flow linked';
                if (($tg->match_mode ?? '') === 'ai' && !$this->aiSettingsService->isEnabled($organizationId)) return 'AI mode requires AI to be enabled';

                $keywords = $tg->keywords ?? [];
                $matchMode = $tg->match_mode ?? 'exact';
                $msg = mb_strtolower(trim($message), 'UTF-8');

                if ($matchMode === 'ai') return 'AI did not classify this message';
                return "No keyword matched (mode: {$matchMode})";

            default:
                return 'Unknown trigger type';
        }
    }

    /**
     * Start a flow walkthrough simulation.
     * Uses Laravel Cache for session persistence (works across PHP-FPM workers).
     */
    public function startFlowSimulation(int $organizationId, int $flowId): array
    {
        $flow = Flow::where('organization_id', $organizationId)
            ->where('id', $flowId)
            ->first();

        if (!$flow || !$flow->metadata) {
            return [
                'success' => false,
                'message' => 'Flow not found or has no content.',
            ];
        }

        $parser = new FlowMetadataParser($flow->metadata);
        $startId = $parser->getStartNodeId();

        if (!$startId) {
            return [
                'success' => false,
                'message' => 'Flow has no start node.',
            ];
        }

        $sessionId = uniqid('sim_', true);
        $messages = [];
        $currentNodeId = $startId;
        $visitedNodes = [];
        $needsInput = null;
        $stoppedForInput = false;

        while ($currentNodeId && !isset($visitedNodes[$currentNodeId])) {
            $visitedNodes[$currentNodeId] = true;
            $node = $parser->getNodeById($currentNodeId);

            if (!$node) {
                $messages[] = (new SimulationStep(
                    nodeId: $currentNodeId,
                    nodeType: 'unknown',
                    category: 'flow_ended',
                    description: 'Node not found',
                    whatsapp: ['type' => 'text', 'body' => 'Flow ended — node not found.'],
                ))->toArray();
                break;
            }

            $step = $this->buildStepForNode($node, $parser, $organizationId);
            $messages[] = $step;

            if (in_array($step['category'], ['waiting_for_input', 'flow_redirect'])) {
                $needsInput = [
                    'node_id' => $currentNodeId,
                    'node_type' => $step['node_type'],
                    'options' => $step['options'] ?? null,
                    'input_type' => $step['whatsapp']['input_type'] ?? null,
                ];
                $stoppedForInput = true;
                break;
            }

            $nextNode = $parser->getNextNode($currentNodeId);
            if (!$nextNode) {
                $messages[] = (new SimulationStep(
                    nodeId: 'end',
                    nodeType: 'end',
                    category: 'flow_ended',
                    description: 'Flow ended',
                    whatsapp: ['type' => 'text', 'body' => 'Flow ended — no more steps.'],
                ))->toArray();
                break;
            }

            $currentNodeId = (string) $nextNode['id'];
        }

        // Only add loop detection if we exited the while loop because of a revisit
        // (not because we stopped for user input or end-of-flow)
        if (!$stoppedForInput && isset($visitedNodes[$currentNodeId])) {
            $messages[] = (new SimulationStep(
                nodeId: $currentNodeId,
                nodeType: 'loop',
                category: 'flow_ended',
                description: 'Loop detected',
                whatsapp: ['type' => 'text', 'body' => 'Flow loop detected — stopping.'],
            ))->toArray();
        }

        // Store session in Cache (30 min TTL)
        $sessionData = [
            'flow_id' => $flowId,
            'organization_id' => $organizationId,
            'flow_metadata' => $flow->metadata,
            'visited_nodes' => $visitedNodes,
            'current_node_id' => $needsInput ? $needsInput['node_id'] : null,
        ];
        Cache::put("sim_{$sessionId}", $sessionData, 1800);

        return [
            'success' => true,
            'session_id' => $sessionId,
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'uuid' => $flow->uuid,
            ],
            'messages' => $messages,
            'needs_input' => $needsInput,
        ];
    }

    /**
     * Advance a flow simulation by providing user input.
     */
    public function advanceFlowSimulation(string $sessionId, string $userInput = '', ?string $selectedOption = null): array
    {
        $sessionData = Cache::get("sim_{$sessionId}");

        if (!$sessionData) {
            return [
                'success' => false,
                'message' => 'Session expired. Start a new test.',
            ];
        }

        $parser = new FlowMetadataParser($sessionData['flow_metadata']);
        $currentNodeId = $sessionData['current_node_id'];
        $visitedNodes = $sessionData['visited_nodes'];
        $organizationId = $sessionData['organization_id'];

        if (!$currentNodeId) {
            return [
                'success' => false,
                'message' => 'Flow has ended. Start a new test.',
            ];
        }

        $messages = [];
        if ($userInput) {
            $messages[] = (new SimulationStep(
                nodeId: 'user',
                nodeType: 'user_message',
                category: 'user_message',
                description: 'User sent: ' . $userInput,
                whatsapp: ['type' => 'user', 'body' => $userInput],
            ))->toArray();
        }

        // Handle portal redirect — start the target flow
        if ($selectedOption && str_starts_with($selectedOption, 'portal_')) {
            $currentNode = $parser->getNodeById($currentNodeId);

            if ($selectedOption === 'portal_continue') {
                $targetFlowUuid = $parser->getNodeFields($currentNodeId)['target_flow_uuid'] ?? '';
                $targetFlow = Flow::where('uuid', $targetFlowUuid)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->first();

                if ($targetFlow) {
                    // End current session and start the target flow
                    Cache::forget("sim_{$sessionId}");
                    return $this->startFlowSimulation($organizationId, $targetFlow->id);
                }

                $messages[] = (new SimulationStep(
                    nodeId: 'end',
                    nodeType: 'end',
                    category: 'flow_ended',
                    description: 'Target flow not found or inactive',
                    whatsapp: ['type' => 'text', 'body' => 'Target flow not found or inactive. Flow ended.'],
                ))->toArray();

                Cache::put("sim_{$sessionId}", array_merge($sessionData, ['current_node_id' => null]), 1800);
                return [
                    'success' => true,
                    'session_id' => $sessionId,
                    'messages' => $messages,
                    'needs_input' => null,
                ];
            }

            // portal_stop — end the flow
            $messages[] = (new SimulationStep(
                nodeId: 'end',
                nodeType: 'end',
                category: 'flow_ended',
                description: 'User stopped at portal',
                whatsapp: ['type' => 'text', 'body' => 'Flow ended.'],
            ))->toArray();

            Cache::put("sim_{$sessionId}", array_merge($sessionData, ['current_node_id' => null]), 1800);
            return [
                'success' => true,
                'session_id' => $sessionId,
                'messages' => $messages,
                'needs_input' => null,
            ];
        }

        // Determine next node
        $nextNode = null;

        if ($selectedOption) {
            $nextNode = $parser->getNextNode($currentNodeId, '', $selectedOption);
        } elseif ($userInput) {
            $nextNode = $parser->getNextNode($currentNodeId, $userInput);
        }

        if (!$nextNode) {
            $edges = $parser->getOutgoingEdges($currentNodeId);
            if (!empty($edges)) {
                $targetId = $edges[0]['target'] ?? null;
                if ($targetId) {
                    $nextNode = $parser->getNodeById((string) $targetId);
                }
            }
        }

        if (!$nextNode) {
            $messages[] = (new SimulationStep(
                nodeId: 'end',
                nodeType: 'end',
                category: 'flow_ended',
                description: 'No matching branch',
                whatsapp: ['type' => 'text', 'body' => 'No matching branch. Flow ended.'],
            ))->toArray();

            Cache::put("sim_{$sessionId}", array_merge($sessionData, ['current_node_id' => null]), 1800);

            return [
                'success' => true,
                'session_id' => $sessionId,
                'messages' => $messages,
                'needs_input' => null,
            ];
        }

        $currentNodeId = (string) $nextNode['id'];
        $needsInput = null;
        $stoppedForInput = false;

        while ($currentNodeId && !isset($visitedNodes[$currentNodeId])) {
            $visitedNodes[$currentNodeId] = true;
            $node = $parser->getNodeById($currentNodeId);

            if (!$node) {
                $messages[] = (new SimulationStep(
                    nodeId: $currentNodeId,
                    nodeType: 'unknown',
                    category: 'flow_ended',
                    description: 'Node not found',
                ))->toArray();
                break;
            }

            $step = $this->buildStepForNode($node, $parser, $organizationId);
            $messages[] = $step;

            if (in_array($step['category'], ['waiting_for_input', 'flow_redirect'])) {
                $needsInput = [
                    'node_id' => $currentNodeId,
                    'node_type' => $step['node_type'],
                    'options' => $step['options'] ?? null,
                    'input_type' => $step['whatsapp']['input_type'] ?? null,
                ];
                $stoppedForInput = true;
                break;
            }

            $nextNode = $parser->getNextNode($currentNodeId);
            if (!$nextNode) {
                $messages[] = (new SimulationStep(
                    nodeId: 'end',
                    nodeType: 'end',
                    category: 'flow_ended',
                    description: 'Flow ended',
                    whatsapp: ['type' => 'text', 'body' => 'Flow ended — no more steps.'],
                ))->toArray();
                break;
            }

            $currentNodeId = (string) $nextNode['id'];
        }

        // Only add loop detection if we exited because of a revisit (not stopped for input)
        if (!$stoppedForInput && isset($visitedNodes[$currentNodeId])) {
            $messages[] = (new SimulationStep(
                nodeId: $currentNodeId,
                nodeType: 'loop',
                category: 'flow_ended',
                description: 'Loop detected',
                whatsapp: ['type' => 'text', 'body' => 'Flow loop detected — stopping.'],
            ))->toArray();
        }

        // Update session in Cache
        Cache::put("sim_{$sessionId}", array_merge($sessionData, [
            'visited_nodes' => $visitedNodes,
            'current_node_id' => $needsInput ? $needsInput['node_id'] : null,
        ]), 1800);

        return [
            'success' => true,
            'session_id' => $sessionId,
            'messages' => $messages,
            'needs_input' => $needsInput,
        ];
    }

    /**
     * Simulate AI response for a message.
     */
    public function simulateAiResponse(int $organizationId, string $message): array
    {
        $aiEnabled = $this->aiSettingsService->isEnabled($organizationId);
        $aiFallbackEnabled = (bool) $this->botSettings->getSetting($organizationId, 'ai_fallback_enabled', false);
        $confidenceThreshold = (int) $this->botSettings->getSetting($organizationId, 'ai_fallback_confidence', 75);

        if (!$aiEnabled) {
            return [
                'success' => false,
                'message' => 'AI is not enabled.',
                'ai_enabled' => false,
                'whatsapp' => ['type' => 'text', 'body' => 'AI is not enabled.'],
            ];
        }

        if (!$aiFallbackEnabled) {
            return [
                'success' => false,
                'message' => 'AI fallback is not enabled.',
                'ai_enabled' => true,
                'ai_fallback_enabled' => false,
                'whatsapp' => ['type' => 'text', 'body' => 'AI fallback is not enabled. Enable it in Behavior & AI settings.'],
            ];
        }

        $virtualContact = new Contact();
        $virtualContact->id = 0;
        $virtualContact->organization_id = $organizationId;

        $escalationMatch = $this->escalationService->evaluate($organizationId, $message);

        if ($escalationMatch !== null) {
            return [
                'success' => true,
                'type' => 'escalation',
                'message' => 'Escalation rule matched.',
                'escalation' => [
                    'name' => $escalationMatch->name,
                    'target_type' => $escalationMatch->target_type ?? 'agent',
                ],
                'ai_enabled' => true,
                'ai_fallback_enabled' => true,
                'confidence_threshold' => $confidenceThreshold,
                'whatsapp' => [
                    'type' => 'text',
                    'body' => "Escalation: \"{$escalationMatch->name}\" — would transfer to a human agent.",
                ],
            ];
        }

        $result = $this->aiFallbackService->attemptAnswer($organizationId, $message, $virtualContact);

        if ($result === null) {
            return [
                'success' => true,
                'type' => 'no_answer',
                'message' => 'AI could not find an answer.',
                'ai_enabled' => true,
                'ai_fallback_enabled' => true,
                'confidence_threshold' => $confidenceThreshold,
                'whatsapp' => [
                    'type' => 'text',
                    'body' => 'No answer found. The fallback trigger would handle this.',
                ],
            ];
        }

        if ($result['type'] === 'escalation') {
            $instruction = $result['instruction'] ?? null;
            return [
                'success' => true,
                'type' => 'escalation',
                'message' => 'AI detected escalation needed.',
                'escalation' => [
                    'name' => $instruction->name ?? 'Unknown',
                    'target_type' => $instruction->target_type ?? 'agent',
                ],
                'ai_enabled' => true,
                'ai_fallback_enabled' => true,
                'confidence_threshold' => $confidenceThreshold,
                'whatsapp' => [
                    'type' => 'text',
                    'body' => 'AI detected this needs escalation to a human agent.',
                ],
            ];
        }

        return [
            'success' => true,
            'type' => 'answer',
            'message' => 'AI found an answer.',
            'answer' => $result['content'] ?? '',
            'ai_enabled' => true,
            'ai_fallback_enabled' => true,
            'confidence_threshold' => $confidenceThreshold,
            'whatsapp' => [
                'type' => 'text',
                'body' => $result['content'] ?? 'No answer available.',
            ],
        ];
    }

    /**
     * Build a SimulationStep for a flow node with WhatsApp-formatted data.
     */
    protected function buildStepForNode(array $node, FlowMetadataParser $parser, int $organizationId): array
    {
        $nodeId = (string) $node['id'];
        $nodeType = $node['type'] ?? 'unknown';
        $fields = $parser->getNodeFields($nodeId);

        switch ($nodeType) {
            case 'start':
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'start',
                    category: 'action_executed',
                    description: 'Flow started',
                    whatsapp: null,
                ))->toArray();

            case 'text':
                $text = $fields['text'] ?? '';
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'text',
                    category: 'message_sent',
                    description: "Send text message",
                    whatsapp: ['type' => 'text', 'body' => $text],
                ))->toArray();

            case 'media':
                $mediaType = $fields['media_type'] ?? 'image';
                $caption = $fields['caption'] ?? '';
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'media',
                    category: 'message_sent',
                    description: "Send {$mediaType}",
                    whatsapp: ['type' => $mediaType, 'url' => '', 'caption' => $caption],
                ))->toArray();

            case 'buttons':
                $text = $fields['text'] ?? '';
                $header = $fields['header'] ?? '';
                $footer = $fields['footer'] ?? '';
                $buttons = $fields['buttons'] ?? [];
                $options = [];
                $buttonMap = ['button1' => 'a', 'button2' => 'b', 'button3' => 'c'];
                foreach ($buttons as $key => $label) {
                    if (is_string($label) && !empty($label)) {
                        $options[] = ['id' => $buttonMap[$key] ?? $key, 'title' => $label];
                    }
                }
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'buttons',
                    category: 'waiting_for_input',
                    description: "Send buttons message",
                    whatsapp: ['type' => 'interactive_buttons', 'header' => $header, 'body' => $text, 'footer' => $footer, 'buttons' => $options],
                    options: $options,
                ))->toArray();

            case 'list':
                $body = $fields['body'] ?? $fields['text'] ?? '';
                $header = $fields['header'] ?? '';
                $footer = $fields['footer'] ?? '';
                $sections = $fields['sections'] ?? [];
                $options = [];
                foreach ($sections as $sIdx => $section) {
                    foreach ($section['rows'] ?? [] as $rIdx => $row) {
                        $options[] = [
                            'id' => 'a' . $sIdx . $rIdx,
                            'title' => $row['title'] ?? '',
                            'description' => $row['description'] ?? '',
                            'section' => $section['title'] ?? '',
                        ];
                    }
                }
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'list',
                    category: 'waiting_for_input',
                    description: "Send list message",
                    whatsapp: ['type' => 'interactive_list', 'header' => $header, 'body' => $body, 'footer' => $footer, 'sections' => $sections],
                    options: $options,
                ))->toArray();

            case 'question':
                $questionText = $fields['question_text'] ?? '';
                $inputType = $fields['input_type'] ?? 'text';
                $variableName = $fields['variable_name'] ?? '';
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'question',
                    category: 'waiting_for_input',
                    description: "Ask question: {$questionText}",
                    whatsapp: ['type' => 'question', 'body' => $questionText, 'input_type' => $inputType, 'variable_name' => $variableName],
                    options: [['id' => 'text_input', 'title' => 'Type your answer']],
                ))->toArray();

            case 'template':
                $templateName = $fields['template_name'] ?? 'Unknown';
                $language = $fields['template_language'] ?? '?';
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'template',
                    category: 'message_sent',
                    description: "Send template: {$templateName}",
                    whatsapp: ['type' => 'template', 'name' => $templateName, 'language' => $language, 'body' => "Template: {$templateName} ({$language})"],
                ))->toArray();

            case 'metaflow':
                $metaflowName = $fields['metaflow_name'] ?? 'Unknown';
                $body = $fields['body'] ?? 'Please complete the form.';
                $cta = $fields['cta'] ?? 'Open';
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'metaflow',
                    category: 'message_sent',
                    description: "Send WhatsApp Flow: {$metaflowName}",
                    whatsapp: ['type' => 'flow', 'name' => $metaflowName, 'body' => $body, 'cta' => $cta],
                ))->toArray();

            case 'portal':
                $targetFlowName = $fields['target_flow_name'] ?? 'Unknown';
                $targetFlowUuid = $fields['target_flow_uuid'] ?? '';

                $targetFlow = Flow::where('uuid', $targetFlowUuid)
                    ->where('organization_id', $organizationId)
                    ->where('status', 'active')
                    ->first();

                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'portal',
                    category: 'flow_redirect',
                    description: "Redirect to flow: {$targetFlowName}",
                    whatsapp: ['type' => 'portal', 'target_flow_name' => $targetFlowName, 'target_flow_active' => $targetFlow !== null, 'body' => "Redirecting to \"{$targetFlowName}\"..."],
                    options: [
                        ['id' => 'portal_continue', 'title' => "Continue to {$targetFlowName}"],
                        ['id' => 'portal_stop', 'title' => 'Stop here'],
                    ],
                ))->toArray();

            case 'action':
                $actionType = $parser->getActionType($nodeId);
                $config = $parser->getActionConfig($nodeId);
                $description = $this->describeAction($actionType, $config);

                // assign_to_agent is a human transfer — show it distinctly
                if ($actionType === 'assign_to_agent') {
                    return (new SimulationStep(
                        nodeId: $nodeId,
                        nodeType: 'assign_to_agent',
                        category: 'action_executed',
                        description: 'Transfer to human agent',
                        whatsapp: ['type' => 'human_transfer', 'body' => 'Conversation transferred to a human agent'],
                    ))->toArray();
                }

                // delay pauses the flow — show it as a waiting step
                if ($actionType === 'delay') {
                    $duration = $config['duration'] ?? 5;
                    $unit = $config['duration_unit'] ?? 'minutes';
                    return (new SimulationStep(
                        nodeId: $nodeId,
                        nodeType: 'delay',
                        category: 'action_executed',
                        description: "Wait {$duration} {$unit}",
                        whatsapp: ['type' => 'delay', 'body' => "Waiting {$duration} {$unit}..."],
                    ))->toArray();
                }

                // ai_response calls AI — show it distinctly
                if ($actionType === 'ai_response') {
                    return (new SimulationStep(
                        nodeId: $nodeId,
                        nodeType: 'ai_response',
                        category: 'action_executed',
                        description: 'AI generates response',
                        whatsapp: ['type' => 'ai_action', 'body' => 'AI will generate a response'],
                    ))->toArray();
                }

                // conditional evaluates a branch — show it
                if ($actionType === 'conditional') {
                    $condition = $config['condition'] ?? 'unknown condition';
                    return (new SimulationStep(
                        nodeId: $nodeId,
                        nodeType: 'conditional',
                        category: 'action_executed',
                        description: "Check condition: {$condition}",
                        whatsapp: ['type' => 'action', 'action_type' => 'conditional', 'body' => "Checking: {$condition}"],
                    ))->toArray();
                }

                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: 'action',
                    category: 'action_executed',
                    description: $description,
                    whatsapp: ['type' => 'action', 'action_type' => $actionType, 'body' => $description],
                ))->toArray();

            default:
                return (new SimulationStep(
                    nodeId: $nodeId,
                    nodeType: $nodeType,
                    category: 'action_executed',
                    description: "Unknown node: {$nodeType}",
                    whatsapp: ['type' => 'text', 'body' => "Unknown node type: {$nodeType}"],
                ))->toArray();
        }
    }

    protected function describeAction(?string $actionType, ?array $config): string
    {
        if (!$actionType) return 'Execute action';

        return match ($actionType) {
            'add_to_group' => 'Add contact to group',
            'remove_from_group' => 'Remove contact from group',
            'update_contact' => 'Update contact field',
            'send_email' => 'Send email',
            'delay' => 'Wait ' . ($config['duration'] ?? 5) . ' ' . ($config['duration_unit'] ?? 'minutes'),
            'webhook' => 'Call webhook',
            'ai_response' => 'AI response',
            'conditional' => 'Check condition',
            'assign_to_agent' => 'Assign to agent',
            'label' => ucfirst($config['action'] ?? 'add') . ' label',
            default => "Action: {$actionType}",
        };
    }
}