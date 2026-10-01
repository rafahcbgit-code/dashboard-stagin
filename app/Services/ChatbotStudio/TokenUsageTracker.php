<?php

namespace App\Services\ChatbotStudio;

use App\Models\ChatbotStudioDraft;
use Illuminate\Support\Facades\DB;

class TokenUsageTracker
{
    public static function emptySummary(bool $historicalUsageUnknown = false): array
    {
        return ['tracked_since' => now()->toIso8601String(), 'historical_usage_unknown' => $historicalUsageUnknown,
            'requests' => 0, 'reported_requests' => 0, 'total_tokens' => 0, 'input_tokens' => 0,
            'output_tokens' => 0, 'thinking_tokens' => 0, 'cached_input_tokens' => 0, 'tool_input_tokens' => 0];
    }

    public function started(string $draftId): void
    {
        // Persist before HTTP so a timeout or killed worker remains visibly unaccounted for.
        $this->update($draftId, function (array $summary) {
            $summary['requests']++;
            return $summary;
        });
    }

    public function completed(string $draftId, mixed $metadata): void
    {
        if (!is_array($metadata) || !is_int($metadata['totalTokenCount'] ?? null) || $metadata['totalTokenCount'] < 0) {
            return;
        }
        $this->update($draftId, function (array $summary) use ($metadata) {
            $summary['reported_requests']++;
            // Use the provider's total, not a sum: cached tokens are already part of the input.
            foreach (['total_tokens' => 'totalTokenCount', 'input_tokens' => 'promptTokenCount',
                'output_tokens' => 'candidatesTokenCount', 'thinking_tokens' => 'thoughtsTokenCount',
                'cached_input_tokens' => 'cachedContentTokenCount', 'tool_input_tokens' => 'toolUsePromptTokenCount'] as $field => $key) {
                $value = $metadata[$key] ?? 0;
                $summary[$field] += is_int($value) && $value >= 0 ? $value : 0;
            }
            return $summary;
        });
    }

    private function update(string $draftId, callable $change): void
    {
        DB::transaction(function () use ($draftId, $change) {
            $draft = ChatbotStudioDraft::lockForUpdate()->find($draftId);
            if ($draft) {
                // Even an obsolete/cancelled revision may have consumed tokens in flight.
                $draft->update(['token_usage' => $change($draft->token_usage ?? self::emptySummary(true))]);
            }
        }, 3);
    }
}
