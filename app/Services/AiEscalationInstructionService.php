<?php

namespace App\Services;

use App\Models\AiEscalationInstruction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiEscalationInstructionService
{
    protected AiService $aiService;
    protected AiSettingsService $aiSettingsService;

    public function __construct(AiService $aiService, AiSettingsService $aiSettingsService)
    {
        $this->aiService = $aiService;
        $this->aiSettingsService = $aiSettingsService;
    }

    public function listAll(int $organizationId)
    {
        return AiEscalationInstruction::forOrganization($organizationId)
            ->with('targetAgent')
            ->orderedByPriority()
            ->get();
    }

    public function create(int $organizationId, array $data): AiEscalationInstruction
    {
        if (!isset($data['priority'])) {
            $maxPriority = AiEscalationInstruction::forOrganization($organizationId)->max('priority');
            $data['priority'] = ($maxPriority ?? -1) + 1;
        }

        $data['organization_id'] = $organizationId;

        return AiEscalationInstruction::create($data);
    }

    public function update(string $uuid, array $data): ?AiEscalationInstruction
    {
        $instruction = AiEscalationInstruction::where('uuid', $uuid)->first();
        if (!$instruction) {
            return null;
        }

        $instruction->update($data);
        return $instruction->fresh();
    }

    public function delete(string $uuid): bool
    {
        $instruction = AiEscalationInstruction::where('uuid', $uuid)->first();
        if (!$instruction) {
            return false;
        }

        $instruction->delete();
        return true;
    }

    public function reorder(int $organizationId, array $orderedUuids): void
    {
        DB::transaction(function () use ($organizationId, $orderedUuids) {
            foreach ($orderedUuids as $index => $uuid) {
                AiEscalationInstruction::forOrganization($organizationId)
                    ->where('uuid', $uuid)
                    ->update(['priority' => $index]);
            }
        });
    }

    public function toggleEnabled(string $uuid, bool $enabled): ?AiEscalationInstruction
    {
        $instruction = AiEscalationInstruction::where('uuid', $uuid)->first();
        if (!$instruction) {
            return null;
        }

        $instruction->update(['is_enabled' => $enabled]);
        return $instruction->fresh();
    }

    /**
     * Evaluate a message against escalation instructions.
     * Returns the first matching instruction, or null.
     */
    public function evaluate(int $organizationId, string $message): ?AiEscalationInstruction
    {
        if (config('ai.suspended', true)) {
            return null;
        }

        // Check if AI is enabled org-wide
        if (!$this->aiSettingsService->isEnabled($organizationId)) {
            return null;
        }

        $instructions = AiEscalationInstruction::forOrganization($organizationId)
            ->enabled()
            ->orderedByPriority()
            ->get();

        if ($instructions->isEmpty()) {
            return null;
        }

        // Build a list of instruction descriptions for AI to match against
        $rulesList = $instructions->map(function ($instruction, $index) {
            return ($index + 1) . ". {$instruction->name}: {$instruction->description}";
        })->implode("\n");

        $systemPrompt = "You are an escalation classifier. Given a user message, determine if it matches any of the escalation rules below. "
            . "Respond with ONLY a JSON object: {\"match\": true/false, \"rule_number\": number_or_null}\n\n"
            . "Rules:\n{$rulesList}";

        try {
            $response = $this->aiService->chat($systemPrompt, $message, [
                'temperature' => 0.1,
                'max_tokens' => 100,
            ]);

            $content = trim($response->content ?? '');

            // Parse JSON response
            $json = json_decode($content, true);
            if (!$json) {
                // Try to extract JSON from markdown code blocks
                if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
                    $json = json_decode($matches[1], true);
                } elseif (preg_match('/\{.*?\}/s', $content, $matches)) {
                    $json = json_decode($matches[0], true);
                }
            }

            if ($json && isset($json['match']) && $json['match'] === true && isset($json['rule_number'])) {
                $ruleNumber = (int) $json['rule_number'] - 1; // 0-indexed
                if (isset($instructions[$ruleNumber])) {
                    Log::info('AiEscalationInstructionService: Escalation match found', [
                        'organization_id' => $organizationId,
                        'instruction_name' => $instructions[$ruleNumber]->name,
                    ]);
                    return $instructions[$ruleNumber];
                }
            }
        } catch (\Throwable $e) {
            Log::error('AiEscalationInstructionService: AI evaluation failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}