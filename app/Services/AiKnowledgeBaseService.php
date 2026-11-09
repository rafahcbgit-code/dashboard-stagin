<?php

namespace App\Services;

use App\Models\AiKnowledgeBase;

class AiKnowledgeBaseService
{
    public function listAll(int $organizationId)
    {
        return AiKnowledgeBase::forOrganization($organizationId)
            ->orderBy('updated_at', 'desc')
            ->get();
    }

    public function create(int $organizationId, array $data): AiKnowledgeBase
    {
        $data['organization_id'] = $organizationId;

        return AiKnowledgeBase::create($data);
    }

    public function update(string $uuid, array $data): ?AiKnowledgeBase
    {
        $entry = AiKnowledgeBase::where('uuid', $uuid)->first();
        if (!$entry) {
            return null;
        }

        $entry->update($data);
        return $entry->fresh();
    }

    public function delete(string $uuid): bool
    {
        $entry = AiKnowledgeBase::where('uuid', $uuid)->first();
        if (!$entry) {
            return false;
        }

        $entry->delete();
        return true;
    }

    public function toggleEnabled(string $uuid, bool $enabled): ?AiKnowledgeBase
    {
        $entry = AiKnowledgeBase::where('uuid', $uuid)->first();
        if (!$entry) {
            return null;
        }

        $entry->update(['is_enabled' => $enabled]);
        return $entry->fresh();
    }

    /**
     * Get all enabled KB entries for an organization, formatted for AI context.
     */
    public function getEnabledForContext(int $organizationId): string
    {
        if (config('ai.suspended', true)) {
            return '';
        }

        $entries = AiKnowledgeBase::forOrganization($organizationId)
            ->enabled()
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = "Q: {$entry->question}\nA: {$entry->answer}";
        }

        return implode("\n\n", $lines);
    }
}