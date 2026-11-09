<?php

namespace Modules\FlowBuilder\Services;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use App\Services\WhatsappService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use Modules\FlowBuilder\Models\FlowUserData;
use App\Services\AgentRoutingService;

class ActionExecutionService
{
    private $whatsappService;
    private $organizationId;
    private $organization;

    private $metaPayload;

    public function __construct($organizationId, $metaPayload = null)
    {
        $this->organizationId = $organizationId;
        $this->metaPayload = $metaPayload;
        
        $this->organization = Organization::find($organizationId);
        $this->initializeWhatsappService();
    }

    private function initializeWhatsappService()
    {
        $config = $this->organization->metadata;
        $config = $config ? json_decode($config, true) : [];

        $accessToken = $config['whatsapp']['access_token'] ?? null;
        $apiVersion = config('graph.api_version');
        $appId = $config['whatsapp']['app_id'] ?? null;
        $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
        $wabaId = $config['whatsapp']['waba_id'] ?? null;

        $this->whatsappService = new WhatsappService($accessToken, $apiVersion, $appId, $phoneNumberId, $wabaId, $this->organizationId);
    }

    /**
     * Execute an action based on its type and configuration
     *
     * @param string $actionType
     * @param array $config
     * @param Contact $contact
     * @param string $userMessage
     * @param object $flowData
     * @param int $contactId
     * @return bool|string
     */
    public function executeAction($actionType, $config, $contact, $userMessage = '', $flowData = null, $contactId = null)
    {
        try {
            switch ($actionType) {
                case 'add_to_group':
                    return $this->executeAddToGroup($config, $contact);
                
                case 'remove_from_group':
                    return $this->executeRemoveFromGroup($config, $contact);
                
                case 'update_contact':
                    return $this->executeUpdateContact($config, $contact, $userMessage);
                
                case 'send_email':
                    return $this->executeSendEmail($config, $contact, $flowData);
                
                case 'delay':
                    return $this->executeDelay($config, $contact, $flowData, $contactId);
                
                case 'webhook':
                    return $this->executeWebhook($config, $contact, $userMessage, $flowData);

                case 'ai_response':
                    return $this->executeAIResponse($config, $contact, $userMessage, $flowData);

                case 'conditional':
                    return $this->executeConditional($config, $contact, $userMessage, $flowData);
                
                case 'assign_to_agent':
                    return $this->executeAssignToAgent($config, $contact);

                case 'label':
                    return $this->executeLabel($config, $contact);
                default:
                    Log::warning("Unknown action type: {$actionType}");
                    return false;
            }
        } catch (\Exception $e) {
            Log::error("Error executing action {$actionType}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add contact to a group
     */
    private function executeAddToGroup($config, $contact)
    {
        $groupId = $config['group_id'] ?? null;
        if (!$groupId) {
            Log::warning("No group_id specified for add_to_group action");
            return false;
        }

        $group = ContactGroup::where('id', $groupId)
            ->where('organization_id', $this->organizationId)
            ->first();

        if (!$group) {
            Log::warning("Group not found: {$groupId}");
            return false;
        }

        // Check if contact is already in the group
        if (!$contact->contactGroups()->where('contact_group_id', $groupId)->exists()) {
            $contact->contactGroups()->attach($groupId);
            // Log::info("Contact {$contact->id} added to group {$groupId}");
        }

        return true;
    }

    /**
     * Remove contact from a group
     */
    private function executeRemoveFromGroup($config, $contact)
    {
        $groupId = $config['group_id'] ?? null;
        if (!$groupId) {
            Log::warning("No group_id specified for remove_from_group action");
            return false;
        }

        $contact->contactGroups()->detach($groupId);
        // Log::info("Contact {$contact->id} removed from group {$groupId}");

        return true;
    }

    /**
     * Add or remove labels on a contact
     */
    private function executeLabel($config, $contact)
    {
        $action = $config['action'] ?? 'add';
        $labelIds = $config['label_ids'] ?? [];

        if (empty($labelIds) || !is_array($labelIds)) {
            Log::warning("No label_ids specified for label action");
            return false;
        }

        // Validate that all labels belong to this organization
        $validIds = \App\Models\Label::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->whereIn('id', $labelIds)
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            Log::warning("No valid labels found for label action", ['label_ids' => $labelIds]);
            return false;
        }

        if ($action === 'add') {
            $contact->labels()->syncWithoutDetaching($validIds);
        } else {
            $contact->labels()->detach($validIds);
        }

        return true;
    }

    /**
     * Update contact field with user's message
     */
    private function executeUpdateContact($config, $contact, $userMessage)
    {
        $targetField = $config['target_field'] ?? null;
        if (!$targetField || !$userMessage) {
            Log::warning("No target_field or userMessage for update_contact action");
            return false;
        }

        // Map field names to actual contact attributes
        $fieldMapping = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'address.street' => 'address->street',
            'address.city' => 'address->city',
            'address.state' => 'address->state',
            'address.zip' => 'address->zip',
            'address.country' => 'address->country'
        ];

        if (isset($fieldMapping[$targetField])) {
            $field = $fieldMapping[$targetField];
            
            // Validate email field
            if ($targetField === 'email' && !empty($userMessage)) {
                if (!filter_var($userMessage, FILTER_VALIDATE_EMAIL)) {
                    Log::warning("Invalid email format provided: {$userMessage}");
                    
                    // Send invalid email message if configured
                    $invalidMessage = $config['invalid_email_message'] ?? 'Please provide a valid email address.';
                    if (!empty($invalidMessage)) {
                        $this->whatsappService->sendMessage($contact->uuid, $invalidMessage);
                    }
                    
                    return false;
                }
            }
            
            if (str_contains($field, '->')) {
                // Handle nested JSON fields like address
                $parts = explode('->', $field);
                $jsonField = $parts[0];
                $jsonKey = $parts[1];
                
                $currentValue = $contact->$jsonField ? json_decode($contact->$jsonField, true) : [];
                $currentValue[$jsonKey] = $userMessage;
                $contact->$jsonField = json_encode($currentValue);
            } else {
                // Handle regular fields
                $contact->$field = $userMessage;
            }
            
            $contact->save();
            // Log::info("Contact {$contact->id} field {$targetField} updated with: {$userMessage}");
            return true;
        } else {
            // Handle custom metadata fields
            $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
            $metadata[$targetField] = $userMessage;
            $contact->metadata = json_encode($metadata);
            $contact->save();
            
            // Log::info("Contact {$contact->id} metadata field {$targetField} updated with: {$userMessage}");
            return true;
        }
    }

    /**
     * Send email to contact
     */
    private function executeSendEmail($config, $contact, $flowData = null)
    {
        if (!$contact->email) {
            Log::warning("Contact {$contact->id} has no email address");
            return false;
        }

        $subject = $config['subject'] ?? '';
        $body = $config['body'] ?? '';
        $smtpHost = $config['smtp_host'] ?? '';
        $smtpPort = $config['smtp_port'] ?? 587;
        $smtpUsername = $config['smtp_username'] ?? '';
        $smtpPassword = $config['smtp_password'] ?? '';
        $smtpEncryption = $config['smtp_encryption'] ?? 'tls';
        $fromName = $config['from_name'] ?? $this->organization->name;
        $fromEmail = $config['from_email'] ?? '';

        // If no from_email is specified, try to use smtp_username if it's a valid email
        if (empty($fromEmail)) {
            if (filter_var($smtpUsername, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $smtpUsername;
            } else {
                Log::warning("No valid from_email specified and smtp_username is not a valid email address");
                return false;
            }
        }

        if (!$subject || !$body || !$smtpHost || !$smtpUsername || !$smtpPassword) {
            Log::warning("Incomplete email configuration for send_email action");
            return false;
        }

        // Replace placeholders in subject and body
        $subject = $this->replacePlaceholders($contact, $subject, $flowData);
        $body = $this->replacePlaceholders($contact, $body, $flowData);

        try {
            // Configure the dedicated flow_smtp mailer with flow-specific credentials.
            // Uses a separate named mailer so the default 'smtp' mailer (platform emails)
            // is never affected, preventing credential bleed in shared queue workers.
            config([
                'mail.mailers.flow_smtp.host' => $smtpHost,
                'mail.mailers.flow_smtp.port' => $smtpPort,
                'mail.mailers.flow_smtp.username' => $smtpUsername,
                'mail.mailers.flow_smtp.password' => $smtpPassword,
                'mail.mailers.flow_smtp.encryption' => $smtpEncryption,
            ]);

            // Purge any cached flow_smtp transport so the new config takes effect
            app('mail.manager')->purge('flow_smtp');

            Mail::mailer('flow_smtp')->raw($body, function ($message) use ($contact, $subject, $fromName, $fromEmail) {
                $message->to($contact->email)
                        ->subject($subject)
                        ->from($fromEmail, $fromName);
            });

            // Purge flow_smtp transport to close the connection.
            // No need to restore config — the default 'smtp' mailer was never touched.
            app('mail.manager')->purge('flow_smtp');

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send email: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute delay action
     */
    private function executeDelay($config, $contact, $flowData = null, $contactId = null)
    {
        $duration = (int) ($config['duration'] ?? 5);
        $unit = $config['duration_unit'] ?? 'minutes';

        // Convert duration to seconds based on unit
        $delaySeconds = match ($unit) {
            'seconds' => max(1, $duration),
            'hours' => $duration * 3600,
            default => $duration * 60, // minutes
        };

        // Clamp to 1 second minimum, 24 hours maximum
        $delaySeconds = max(1, min($delaySeconds, 86400));

        // Schedule a delayed job to continue the flow after the delay
        if ($flowData && $contactId) {
            \Modules\FlowBuilder\Jobs\ProcessDelayedFlowJob::dispatch($contactId, $flowData->flow_id, $flowData->current_step, $this->organizationId)
                ->delay(now()->addSeconds($delaySeconds));

            return 'delayed'; // Return special value to indicate the flow is paused
        }

        // Fallback: if no flow data, log warning and proceed without blocking
        Log::warning('Delay action executed without flow data, skipping delay', [
            'contact_id' => $contactId,
        ]);

        return true;
    }

    /**
     * Execute webhook action
     */
    private function executeWebhook($config, $contact, $userMessage, $flowData = null)
    {
        $url = trim($config['url'] ?? '');
        $method = strtoupper(trim($config['method'] ?? 'POST'));

        if (!$url) {
            Log::warning("No URL specified for webhook action");
            return false;
        }

        // Resolve {{flow.*}} and {{contact.*}} placeholders in URL
        $url = $this->replacePlaceholders($contact, $url, $flowData);

        // Prepare payload with contact data and flow variables
        $payload = [
            'contact' => [
                'uuid' => $contact->uuid,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'address' => $contact->address ? json_decode($contact->address, true) : [],
                'metadata' => $contact->metadata ? json_decode($contact->metadata, true) : [],
            ],
            'message' => $userMessage,
            'timestamp' => now()->toISOString(),
            'flow_variables' => $flowData ? ($flowData->variables ?? []) : [],
        ];

        // Resolve placeholders in custom body if provided
        $customBody = $config['body'] ?? null;
        if ($customBody) {
            $customBody = $this->replacePlaceholders($contact, $customBody, $flowData);
            $decodedBody = json_decode($customBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                $payload = array_merge($payload, $decodedBody);
            }
        }

        try {
            $headers = [];
            $customHeaders = $config['headers'] ?? [];
            if (is_array($customHeaders)) {
                foreach ($customHeaders as $header) {
                    $key = $header['key'] ?? '';
                    $value = $header['value'] ?? '';
                    if ($key && $value) {
                        $headers[$key] = $value;
                    }
                }
            }

            $response = Http::timeout(30)->withHeaders($headers)->send($method, $url, [
                'json' => $payload,
            ]);

            if ($response->successful()) {
                // Map response fields to flow variables
                $mapping = $config['response_mapping'] ?? [];
                if (!empty($mapping) && $flowData) {
                    $responseData = $response->json();
                    $vars = $flowData->variables ?? [];
                    foreach ($mapping as $map) {
                        $field = $map['response_field'] ?? '';
                        $varName = $map['save_to_variable'] ?? '';
                        if ($field && $varName) {
                            $vars[$varName] = data_get($responseData, $field, '');
                        }
                    }
                    $flowData->variables = $vars;
                    $flowData->save();
                }

                return true;
            } else {
                Log::warning("Webhook failed with status {$response->status()}: {$url}");
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Webhook execution error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute AI response action.
     * Uses org's KB automatically. Confidence threshold fixed at 0.75.
     * If confident: send answer, flow continues. If not: send low_confidence_message, flow pauses.
     */
    private function executeAIResponse($config, $contact, $userMessage, $flowData = null)
    {
        if (config('ai.suspended', true)) {
            Log::info('AI response action skipped: temporarily suspended', ['contact_id' => $contact->id]);
            return true;
        }

        $lowConfidenceMessage = $config['low_confidence_message'] ?? '';
        $confidenceThreshold = 0.75;

        try {
            // 1. Get KB context (auto from org)
            $kbService = new \App\Services\AiKnowledgeBaseService();
            $kbContext = $kbService->getEnabledForContext($this->organizationId);

            // 2. Build system prompt with KB context
            $systemPrompt = $this->buildAiSystemPrompt($kbContext);

            // 3. Call AI
            $aiService = new \App\Services\AiService();
            $response = $aiService->chat($systemPrompt, $userMessage, [
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);

            if (!$response || !$response->success || empty(trim($response->content))) {
                Log::warning("AI response action: AI call failed or empty", [
                    'contact_id' => $contact->id,
                ]);
                return false;
            }

            // 4. Parse structured JSON response
            $parsed = $this->parseAiStructuredResponse(trim($response->content));

            if ($parsed === null) {
                Log::warning("AI response action: Could not parse structured response", [
                    'contact_id' => $contact->id,
                    'raw_content' => mb_substr($response->content, 0, 200),
                ]);
                return false;
            }

            $answer = $parsed['answer'] ?? '';
            $confidence = (float) ($parsed['confidence'] ?? 0);

            if (empty(trim($answer)) || strtoupper(trim($answer)) === 'NONE') {
                Log::info("AI response action: AI returned NONE, no answer available", [
                    'contact_id' => $contact->id,
                ]);

                if (!empty($lowConfidenceMessage)) {
                    $lowConfidenceMessage = $this->replacePlaceholders($contact, $lowConfidenceMessage, $flowData);
                    $this->whatsappService->sendMessage($contact->uuid, $lowConfidenceMessage);
                }

                return false;
            }

            // 5. Check confidence threshold BEFORE sending the answer
            if ($confidence < $confidenceThreshold) {
                Log::info("AI response action: confidence below threshold, sending low_confidence_message and pausing flow", [
                    'contact_id' => $contact->id,
                    'confidence' => $confidence,
                    'threshold' => $confidenceThreshold,
                ]);

                if (!empty($lowConfidenceMessage)) {
                    $lowConfidenceMessage = $this->replacePlaceholders($contact, $lowConfidenceMessage, $flowData);
                    $this->whatsappService->sendMessage($contact->uuid, $lowConfidenceMessage);
                }

                return false;
            }

            // 6. Confidence is good — send the AI answer via WhatsApp
            $this->whatsappService->sendMessage($contact->uuid, $answer);

            Log::info("AI response action: answer sent", [
                'contact_id' => $contact->id,
                'confidence' => $confidence,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("AI response action error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Build the system prompt for AI response action.
     * Same structured JSON format as AiFallbackService.
     */
    private function buildAiSystemPrompt(string $kbContext): string
    {
        return "You are a customer support assistant. Answer the user's question using ONLY the information from the knowledge base below.\n\n"
            . "RULES:\n"
            . "- You MUST respond with valid JSON in this exact format:\n"
            . '  {"answer": "your answer here", "confidence": 0.0_to_1.0}' . "\n"
            . "- ONLY answer if the user is asking a genuine question that can be answered from the knowledge base.\n"
            . "- If the user's message is NOT a question, set answer to \"NONE\" and confidence to 0.\n"
            . "- If you cannot find the answer in the knowledge base, set answer to \"NONE\" and confidence to 0.\n"
            . "- Be strict: confidence must reflect how well the knowledge base answers THIS specific question.\n"
            . "- Never guess or infer answers not explicitly in the knowledge base.\n"
            . "- Keep answers under 3 sentences.\n"
            . "- Respond in the same language as the user's message.\n"
            . "- Do not mention the knowledge base or that you are an AI.\n\n"
            . "KNOWLEDGE BASE:\n" . $kbContext;
    }

    /**
     * Parse a structured JSON response from the AI.
     * Same logic as AiFallbackService::parseStructuredResponse().
     */
    private function parseAiStructuredResponse(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['answer'])) {
            return $decoded;
        }

        if (preg_match('/\{[^{}]*"answer"[^{}]*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['answer'])) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Execute conditional action
     */
    private function executeConditional($config, $contact, $userMessage, $flowData = null)
    {
        $conditionType = $config['condition_type'] ?? 'message_contains';
        $conditions = $config['conditions'] ?? [];
        $fieldName = $config['field_name'] ?? '';
        $fieldOperator = $config['field_operator'] ?? 'equals';

        // This method should return the condition that matches
        // The actual flow branching logic is handled in FlowExecutionService
        foreach ($conditions as $index => $condition) {
            $conditionValue = $condition['value'] ?? '';

            // Resolve {{flow.*}} and {{contact.*}} placeholders in condition values
            if ($flowData) {
                $conditionValue = $this->replacePlaceholders($contact, $conditionValue, $flowData);
            }

            if ($this->evaluateCondition($conditionType, $conditionValue, $userMessage, $contact, $fieldName, $fieldOperator, $flowData)) {
                // Log::info("Conditional action: condition {$index} matched");
                return $index; // Return the condition index that matched
            }
        }

        // Log::info("Conditional action: no conditions matched, using default");
        return 'default'; // Return 'default' if no conditions match
    }

    /**
     * Evaluate a single condition
     */
    private function evaluateCondition($conditionType, $conditionValue, $userMessage, $contact, $fieldName, $fieldOperator, $flowData = null)
    {
        switch ($conditionType) {
            case 'message_contains':
                return stripos($userMessage, $conditionValue) !== false;

            case 'message_equals':
                return strtolower(trim($userMessage)) === strtolower(trim($conditionValue));

            case 'contact_field':
                if (!$fieldName) return false;

                $fieldValue = $this->getContactFieldValue($contact, $fieldName);
                return $this->compareValues($fieldValue, $fieldOperator, $conditionValue);

            case 'flow_variable':
                if (!$fieldName || !$flowData) return false;

                $flowVars = $flowData->variables ?? [];
                $fieldValue = $flowVars[$fieldName] ?? '';
                return $this->compareValues($fieldValue, $fieldOperator, $conditionValue);

            default:
                return false;
        }
    }

    /**
     * Get contact field value
     */
    private function getContactFieldValue($contact, $fieldName)
    {
        $fieldMapping = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];

        if (isset($fieldMapping[$fieldName])) {
            return $fieldMapping[$fieldName];
        }

        // Handle address fields
        if (str_starts_with($fieldName, 'address.')) {
            $address = $contact->address ? json_decode($contact->address, true) : [];
            $key = str_replace('address.', '', $fieldName);
            return $address[$key] ?? '';
        }

        // Handle metadata fields
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];
        return $metadata[$fieldName] ?? '';
    }

    /**
     * Compare values based on operator
     */
    private function compareValues($fieldValue, $operator, $conditionValue)
    {
        switch ($operator) {
            case 'equals':
                return strtolower(trim($fieldValue)) === strtolower(trim($conditionValue));
            
            case 'contains':
                return stripos($fieldValue, $conditionValue) !== false;
            
            case 'not_equals':
                return strtolower(trim($fieldValue)) !== strtolower(trim($conditionValue));
            
            case 'is_empty':
                return empty(trim($fieldValue));
            
            case 'is_not_empty':
                return !empty(trim($fieldValue));

            case 'greater_than':
                return is_numeric($fieldValue) && is_numeric($conditionValue) && floatval($fieldValue) > floatval($conditionValue);

            case 'less_than':
                return is_numeric($fieldValue) && is_numeric($conditionValue) && floatval($fieldValue) < floatval($conditionValue);
            
            default:
                return false;
        }
    }

    /**
     * Replace placeholders in text
     */
    private function replacePlaceholders($contact, $text, ?FlowUserData $flowData = null)
    {
        $address = $contact->address ? json_decode($contact->address, true) : [];
        $metadata = $contact->metadata ? json_decode($contact->metadata, true) : [];

        $fullAddress = ($address['street'] ?? '') . ', ' .
                      ($address['city'] ?? '') . ', ' .
                      ($address['state'] ?? '') . ', ' .
                      ($address['zip'] ?? '') . ', ' .
                      ($address['country'] ?? '');

        $contactData = [
            'first_name' => $contact->first_name ?? '',
            'last_name' => $contact->last_name ?? '',
            'full_name' => $contact->full_name ?? '',
            'email' => $contact->email ?? '',
            'phone' => $contact->phone ?? '',
            'organization_name' => $this->organization->name ?? '',
            'full_address' => $fullAddress,
            'street' => $address['street'] ?? '',
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? '',
            'zip_code' => $address['zip'] ?? '',
            'country' => $address['country'] ?? '',
        ];

        // Add metadata
        foreach ($metadata as $key => $value) {
            $contactData[strtolower(str_replace(' ', '_', $key))] = $value;
        }

        $flowVariables = ($flowData?->variables) ?? [];
        $systemData = [
            'current_date' => now()->format('Y-m-d'),
            'current_time' => now()->format('H:i'),
            'organization_name' => $this->organization->name ?? '',
        ];

        // Replace {{system.*}}
        $text = preg_replace_callback('/\{\{system\.(\w+)\}\}/', function ($m) use ($systemData) {
            return $systemData[$m[1]] ?? $m[0];
        }, $text);

        // Replace {{flow.*}}
        $text = preg_replace_callback('/\{\{flow\.(\w+)\}\}/', function ($m) use ($flowVariables) {
            return $flowVariables[$m[1]] ?? $m[0];
        }, $text);

        // Replace {{contact.*}}
        $text = preg_replace_callback('/\{\{contact\.(\w+)\}\}/', function ($m) use ($contactData) {
            return $contactData[$m[1]] ?? $m[0];
        }, $text);

        // Replace legacy {flat} placeholders
        $placeholders = [];
        foreach ($contactData as $key => $value) {
            $placeholders['{' . $key . '}'] = $value;
        }

        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }


    /**
     * Execute assign to agent action
     */
    private function executeAssignToAgent($config, $contact)
    {
        $assignmentType = $config['assignment_type'] ?? 'specific_agent';
        $agentId = isset($config['agent_id']) ? (int) $config['agent_id'] : null;

        try {
            $routingService = new AgentRoutingService();
            $ticket = $routingService->assignToAgent(
                $this->organizationId,
                $contact->id,
                $assignmentType,
                $assignmentType === 'specific_agent' ? $agentId : null,
                false
            );

            if ($ticket) {
                Log::info("Contact assigned to agent via flow", [
                    'contact_id' => $contact->id,
                    'assignment_type' => $assignmentType,
                    'assigned_to' => $ticket->assigned_to,
                    'ticket_id' => $ticket->id
                ]);
                return true;
            }

            Log::warning("Agent routing returned no ticket", [
                'contact_id' => $contact->id,
                'assignment_type' => $assignmentType,
            ]);
            return false;
        } catch (\Exception $e) {
            Log::error("Failed to assign contact to agent: " . $e->getMessage());
            return false;
        }
    }
} 
