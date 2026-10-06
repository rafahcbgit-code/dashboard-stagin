<?php

namespace App\Services;

final class DashboardReadinessService
{
    public function __construct(private readonly CampaignReadinessService $campaignReadiness)
    {
    }

    /**
     * Missing WhatsApp configuration is represented by the existing setup card.
     * Once configuration exists, reuse the shared campaign readiness rules.
     *
     * @return array{ready: bool, blockers: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>}|null
     */
    public function check(?array $metadata): ?array
    {
        if (! is_array($metadata['whatsapp'] ?? null)) {
            return null;
        }

        return $this->campaignReadiness->check($metadata);
    }
}
