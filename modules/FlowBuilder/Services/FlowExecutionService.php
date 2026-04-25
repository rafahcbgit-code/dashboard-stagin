<?php

namespace Modules\FlowBuilder\Services;

use App\Helpers\CustomHelper;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\BotSettingsService;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\Flow;
use Modules\FlowBuilder\Models\FlowLog;
use Modules\FlowBuilder\Models\FlowMedia;
use Modules\FlowBuilder\Models\FlowUserData;
use Modules\FlowBuilder\Services\ActionExecutionService;

class FlowExecutionService
{
    private WhatsappService $whatsappService;
    private int $organizationId;
    private $metaPayload;

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
            $flowData->delete();
            return false;
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
            $flow = Flow::where('id', $flowId)
                ->where('organization_id', $chat->organization_id)
                ->where('status', 'active')
                ->first();

            if (!$flow) {
                Log::warning('FlowExecution::startFlow - flow not found or inactive', [
                    'flow_id' => $flowId,
                    'org_id' => $chat->organization_id,
                ]);
                return false;
            }

            // Clear any existing flow state for this contact
            FlowUserData::where('contact_id', $chat->contact_id)->delete();

            $parser = new FlowMetadataParser($flow->metadata);
            $startNodeId = $parser->getStartNodeId();

            $flowData = FlowUserData::create([
                'contact_id' => $chat->contact_id,
                'flow_id' => $flow->id,
                'current_step' => $startNodeId ?? 1,
            ]);

            $message = $this->extractMessageText($chat);
            $result = $this->processFlow($chat, $flowData, strtolower(trim($message)));

            return $result !== false;
        } catch (\Throwable $e) {
            Log::error('FlowExecution::startFlow failed', [
                'flow_id' => $flowId,
                'error' => $e->getMessage(),
            ]);
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
            return false;
        }

        $contact = Contact::find($chat->contact_id);
        if (!$contact) {
            return false;
        }

        $parser = new FlowMetadataParser($flow->metadata);
        $visited = []; // Track visited nodes to prevent infinite loops
        $maxSteps = 50;

        $currentNodeId = (string) $flowData->current_step;

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
                Log::info("FlowExecution: node {$currentNodeId} not found, ending flow");
                $this->endFlow($chat->contact_id);
                return false;
            }

            $nodeType = $node['type'] ?? null;

            // Start node: skip over it to the first real node
            if ($nodeType === 'start') {
                $nextNode = $parser->getNextNode($currentNodeId);
                if (!$nextNode) {
                    $this->endFlow($chat->contact_id);
                    return false;
                }
                $currentNodeId = (string) $nextNode['id'];
                $this->updateStep($chat->contact_id, $currentNodeId);
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

            // Message node: send and stop (wait for user response)
            $sent = $this->sendNodeMessage($node, $contact);
            if ($sent) {
                $this->advanceToNextStep($parser, $currentNodeId, $chat->contact_id);
            } else {
                // Even on send failure, advance to avoid getting stuck
                $this->advanceToNextStep($parser, $currentNodeId, $chat->contact_id);
            }
            return true;
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

    private function sendNodeMessage(array $node, Contact $contact): bool
    {
        $fields = \Arr::get($node, 'data.metadata.fields', []);
        $type = $fields['type'] ?? null;

        if (!$type) {
            return false;
        }

        $message = $this->replacePlaceholders($contact->uuid, $fields['body'] ?? '');
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

    // ─── Flow State ─────────────────────────────────────────────

    private function advanceToNextStep(FlowMetadataParser $parser, string $currentNodeId, int $contactId): void
    {
        $nextNode = $parser->getNextNode($currentNodeId);

        if ($nextNode && isset($nextNode['id'])) {
            $this->updateStep($contactId, (string) $nextNode['id']);

            // Log the message in flow_logs
            $flowData = FlowUserData::where('contact_id', $contactId)->first();
            if ($flowData) {
                FlowLog::create(['flow_id' => $flowData->flow_id]);
            }
        } else {
            $this->endFlow($contactId);
        }
    }

    private function updateStep(int $contactId, string $step): void
    {
        FlowUserData::where('contact_id', $contactId)->update(['current_step' => $step]);
    }

    private function endFlow(int $contactId): void
    {
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

    private function replacePlaceholders(string $contactUuid, string $message): string
    {
        $organization = Organization::where('id', $this->organizationId)->first();
        $contact = Contact::with('contactGroups')->where('uuid', $contactUuid)->first();

        if (!$contact) {
            return $message;
        }

        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];

        $fullAddress = collect([
            $address['street'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['zip'] ?? null,
            $address['country'] ?? null,
        ])->filter()->implode(', ');

        $data = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'full_name' => $contact->full_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'organization_name' => $organization->name ?? '',
            'full_address' => $fullAddress,
            'street' => $address['street'] ?? null,
            'city' => $address['city'] ?? null,
            'state' => $address['state'] ?? null,
            'zip_code' => $address['zip'] ?? null,
            'country' => $address['country'] ?? null,
        ];

        // Merge contact metadata with transformed keys
        if ($metadata) {
            foreach ($metadata as $key => $value) {
                $data[strtolower(str_replace(' ', '_', $key))] = $value;
            }
        }

        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($data) {
            return $data[$m[1]] ?? $m[0];
        }, $message);
    }
}