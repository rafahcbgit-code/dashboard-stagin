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
    public function attemptAnswer(int $organizationId, string $message, $contact): ?array
    {
        // 1. Check if AI is globally enabled for this org
        if (!$this->aiSettingsService->isEnabled($organizationId)) {
            return null;
        }

        // 2. Check if AI fallback is enabled
        if (!$this->botSettings->getSetting($organizationId, 'ai_fallback_enabled', false)) {
            return null;
        }

        // 3. Check escalation instructions first
        $escalationMatch = $this->escalationService->evaluate($organizationId, $message);
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

        // 4. Get KB context
        $kbContext = $this->kbService->getEnabledForContext($organizationId);

        if (empty(trim($kbContext))) {
            Log::info('AiFallbackService: No KB entries found, skipping AI fallback', [
                'organization_id' => $organizationId,
            ]);
            return null;
        }

        // 5. Get confidence threshold
        $confidenceThreshold = (int) $this->botSettings->getSetting(
            $organizationId,
            'ai_fallback_confidence',
            75
        );

        // 6. Build system prompt
        $systemPrompt = $this->buildSystemPrompt($kbContext);

        // 7. Call AI
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

        if (!$response || empty(trim($response->content))) {
            return null;
        }

        $content = trim($response->content);

        // 8. Check for explicit "I don't know" signal
        if (strtoupper($content) === 'NONE') {
            return null;
        }

        // 9. Check confidence if available
        if ($response->confidence !== null && ($response->confidence * 100) < $confidenceThreshold) {
            Log::info('AiFallbackService: AI confidence below threshold', [
                'organization_id' => $organizationId,
                'confidence' => $response->confidence,
                'threshold' => $confidenceThreshold / 100,
            ]);
            return null;
        }

        Log::info('AiFallbackService: AI fallback answer sent', [
            'organization_id' => $organizationId,
            'confidence' => $response->confidence,
        ]);

        return [
            'type' => 'answer',
            'content' => $content,
        ];
    }

    /**
     * Build the system prompt with KB context.
     */
    protected function buildSystemPrompt(string $kbContext): string
    {
        return "You are a customer support assistant. Answer the user's question using ONLY the information from the knowledge base below.\n\n"
            . "RULES:\n"
            . "- If you can answer the question based on the knowledge base, provide a clear and concise answer.\n"
            . "- If you cannot find the answer in the knowledge base, respond with exactly: NONE\n"
            . "- Keep answers under 3 sentences.\n"
            . "- Respond in the same language as the user's message.\n"
            . "- Do not mention the knowledge base or that you are an AI.\n\n"
            . "KNOWLEDGE BASE:\n"
            . $kbContext;
    }
}