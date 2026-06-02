<?php

namespace App\Services\Meta\WhatsApp;

class TemplateStatusMapper
{
    public const UNKNOWN_INTERNAL_STATUS = 'unknown';

    public function map(?string $event): array
    {
        $event = strtoupper((string) $event);

        return match ($event) {
            'APPROVED', 'REINSTATED' => [
                'event' => $event,
                'template_status' => 'APPROVED',
                'normalized_status' => 'active',
                'quality_state' => null,
                'clears_rejection' => true,
                'clears_disabled_at' => true,
            ],
            'REJECTED' => [
                'event' => $event,
                'template_status' => 'REJECTED',
                'normalized_status' => 'rejected',
                'quality_state' => null,
                'clears_rejection' => false,
                'clears_disabled_at' => true,
            ],
            'PENDING' => [
                'event' => $event,
                'template_status' => 'PENDING',
                'normalized_status' => 'pending_review',
                'quality_state' => null,
                'clears_rejection' => true,
                'clears_disabled_at' => true,
            ],
            'PAUSED' => [
                'event' => $event,
                'template_status' => 'PAUSED',
                'normalized_status' => 'paused',
                'quality_state' => 'quality_issue',
                'clears_rejection' => false,
                'clears_disabled_at' => true,
            ],
            'DISABLED' => [
                'event' => $event,
                'template_status' => 'DISABLED',
                'normalized_status' => 'disabled',
                'quality_state' => 'disabled',
                'clears_rejection' => false,
                'clears_disabled_at' => false,
            ],
            'IN_APPEAL' => [
                'event' => $event,
                'template_status' => 'IN_APPEAL',
                'normalized_status' => 'in_appeal',
                'quality_state' => null,
                'clears_rejection' => false,
                'clears_disabled_at' => true,
            ],
            default => [
                'event' => $event ?: 'UNKNOWN',
                'template_status' => null,
                'normalized_status' => self::UNKNOWN_INTERNAL_STATUS,
                'quality_state' => null,
                'clears_rejection' => false,
                'clears_disabled_at' => false,
            ],
        };
    }
}
