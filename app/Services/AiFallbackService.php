<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AiFallbackService
{
    protected AiService $aiService;
    protected AiKnowledgeBaseService $kbService;
    protected BotSettingsService $botSettings;
    protected AiSettingsService $aiSettingsService;
    protected AiEscalationInstructionService $escalationService;

    public function __construct(
        AiService $aiService,
        AiKnowledgeBaseService $kbService,
        BotSettingsService $botSettings,
        AiSettingsService $aiSettingsService,
        AiEscalationInstructionService $escalationService
    ) {
        $this->aiService = $aiService;
        $this->kbService = $kbService;
        $this->botSettings = $botSettings;
        $this->aiSettingsService = $aiSettingsService;
        $this->escalationService = $escalationService;
    }

    /**
     * Attempt to answer a message using AI and the organization's knowledge base.
     * Returns an array with 'type' => 'answer'|'escalation' and relevant data, or null.
     */
    public function attemptAnswer(int $organizationId, string $message, $contact, array $settingOverrides = []): ?array
    {
        if (config('ai.suspended', true)) {
            return null;
        }

        // 1. Check if AI is globally enabled for this org
        $aiEnabled = $settingOverrides['ai_enabled'] ?? $this->aiSettingsService->isEnabled($organizationId);
        if (!$aiEnabled) {
            return null;
        }

        // 2. Check if AI fallback is enabled
        $aiFallbackEnabled = $settingOverrides['ai_fallback_enabled'] ?? (bool) $this->botSettings->getSetting($organizationId, 'ai_fallback_enabled', false);
        if (!$aiFallbackEnabled) {
            return null;
        }

        // 3. Heuristic: skip AI if the message is clearly not a question
        if (!$this->isLikelyQuestion($message)) {
            Log::info('AiFallbackService: Message not a question, skipping AI fallback', [
                'organization_id' => $organizationId,
                'message_preview' => mb_substr($message, 0, 50),
            ]);
            return null;
        }

        // 4. Check escalation instructions first (only if AI escalation is enabled)
        $escalationMatch = null;
        $aiEscalationEnabled = $settingOverrides['ai_escalation_enabled'] ?? $this->botSettings->isAiEscalationEnabled($organizationId);
        if ($aiEscalationEnabled) {
            $escalationMatch = $this->escalationService->evaluate($organizationId, $message);
        }
        if ($escalationMatch !== null) {
            Log::info('AiFallbackService: Escalation instruction matched, skipping KB answer', [
                'organization_id' => $organizationId,
                'instruction_name' => $escalationMatch->name,
                'target_type' => $escalationMatch->target_type,
            ]);

            return [
                'type' => 'escalation',
                'instruction' => $escalationMatch,
            ];
        }

        // 5. Get KB context
        $kbContext = $this->kbService->getEnabledForContext($organizationId);

        if (empty(trim($kbContext))) {
            Log::info('AiFallbackService: No KB entries found, skipping AI fallback', [
                'organization_id' => $organizationId,
            ]);
            return null;
        }

        // 6. Get confidence threshold (global admin setting)
        $confidenceThreshold = $this->aiSettingsService->getDefaultConfidence($organizationId);

        // 7. Build system prompt
        $systemPrompt = $this->buildSystemPrompt($kbContext);

        // 8. Call AI
        try {
            $response = $this->aiService->chat($systemPrompt, $message, [
                'temperature' => 0.3,
                'max_tokens' => 500,
            ]);
        } catch (\Throwable $e) {
            Log::error('AiFallbackService: AI call failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (!$response || !$response->success || empty(trim($response->content))) {
            return null;
        }

        $rawContent = trim($response->content);

        Log::debug('AiFallbackService: Raw AI response', [
            'organization_id' => $organizationId,
            'raw_content' => $rawContent,
        ]);

        // 9. Parse structured response — AI returns JSON with confidence
        $parsed = $this->parseStructuredResponse($rawContent);

        if ($parsed === null) {
            // Fallback: check for explicit "NONE" signal from old-style responses
            if (strtoupper($rawContent) === 'NONE') {
                return null;
            }
            // Unstructured response — treat as low confidence since AI didn't follow format
            Log::info('AiFallbackService: AI response not structured, treating as low confidence', [
                'organization_id' => $organizationId,
            ]);
            return null;
        }

        $content = $parsed['answer'] ?? '';
        $confidence = (float) ($parsed['confidence'] ?? 0);

        Log::debug('AiFallbackService: Parsed AI response', [
            'organization_id' => $organizationId,
            'answer_preview' => mb_substr($content, 0, 80),
            'confidence' => $confidence,
        ]);

        if (empty(trim($content)) || strtoupper(trim($content)) === 'NONE') {
            Log::info('AiFallbackService: AI returned NONE, skipping', [
                'organization_id' => $organizationId,
            ]);
            return null;
        }

        // 10. Check confidence against threshold
        if (($confidence * 100) < $confidenceThreshold) {
            Log::info('AiFallbackService: AI confidence below threshold', [
                'organization_id' => $organizationId,
                'confidence' => $confidence,
                'threshold' => $confidenceThreshold / 100,
            ]);
            // Return the answer with below_threshold flag so callers can still see it
            return [
                'type' => 'answer',
                'content' => $content,
                'confidence' => $confidence,
                'below_threshold' => true,
            ];
        }

        Log::info('AiFallbackService: AI fallback answer sent', [
            'organization_id' => $organizationId,
            'confidence' => $confidence,
        ]);

        return [
            'type' => 'answer',
            'content' => $content,
        ];
    }

    /**
     * Heuristic check: is the message likely a question or information request?
     * Skips AI for messages that are clearly data formats, random text, or non-questions.
     */
    protected function isLikelyQuestion(string $message): bool
    {
        $message = trim($message);

        // Too short to be a meaningful question
        if (mb_strlen($message) < 3) {
            return false;
        }

        // Looks like an email address
        if (filter_var($message, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Looks like a phone number (digits, spaces, dashes, plus, parens)
        $phonePattern = '/^[\d\s\-\+\(\)]{7,20}$/';
        if (preg_match($phonePattern, $message)) {
            return false;
        }

        // Looks like a URL
        if (preg_match('/^https?:\/\//i', $message) || filter_var($message, FILTER_VALIDATE_URL)) {
            return false;
        }

        // All same character (e.g. "aaaaaa", "??????")
        if (preg_match('/^(.)\1{4,}$/', $message)) {
            return false;
        }

        // Primarily random/special characters with no real words
        // A "real word" = at least 3 consecutive letters (Latin, Arabic, Cyrillic)
        if (!preg_match('/[a-zA-Z\x{0600}-\x{06FF}\x{0400}-\x{04FF}]{3,}/u', $message)) {
            return false;
        }

        return true;
    }

    /**
     * Build the system prompt with KB context.
     */
    protected function buildSystemPrompt(string $kbContext): string
    {
        return "You are a customer support assistant. Answer the user's question using ONLY the information from the knowledge base below.\n\n"
            . "RULES:\n"
            . "- You MUST respond with valid JSON in this exact format:\n"
            . '  {"answer": "your answer here", "confidence": 0.0_to_1.0}' . "\n"
            . "- ONLY answer if the user is asking a genuine question that can be answered from the knowledge base.\n"
            . "- If the user's message is NOT a question (e.g. a greeting, statement, email, phone number, random text, or any non-question input), set answer to \"NONE\" and confidence to 0.\n"
            . "- If you cannot find the answer in the knowledge base, set answer to \"NONE\" and confidence to 0.\n"
            . "- Be strict: confidence must reflect how well the knowledge base answers THIS specific question. If the KB does not directly address the question, confidence should be low (< 0.5).\n"
            . "- Never guess or infer answers that are not explicitly in the knowledge base.\n"
            . "- Keep answers under 3 sentences.\n"
            . "- Respond in the same language as the user's message.\n"
            . "- Do not mention the knowledge base or that you are an AI.\n\n"
            . "KNOWLEDGE BASE:\n"
            . $kbContext;
    }

    /**
     * Parse a structured JSON response from the AI.
     */
    protected function parseStructuredResponse(string $content): ?array
    {
        $content = trim($content);

        // Strip markdown code blocks if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['answer'])) {
            return $decoded;
        }

        // Try to extract JSON from the content
        if (preg_match('/\{[^{}]*"answer"[^{}]*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['answer'])) {
                return $decoded;
            }
        }

        return null;
    }
}