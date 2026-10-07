<?php

namespace App\Services\Templates;

use App\Models\Template;
use App\Models\TemplateStatusEvent;
use Illuminate\Support\Str;

class TemplateStatusHistoryService
{
    public function record(
        Template $template,
        ?string $oldStatus,
        ?string $newStatus,
        ?string $oldCategory = null,
        ?string $newCategory = null,
        ?string $reason = null,
        string $source = 'webhook',
        array $context = []
    ): void {
        if ($oldStatus === $newStatus && $oldCategory === $newCategory) {
            return;
        }

        $fingerprint = hash('sha256', implode('|', [
            $template->id,
            $oldStatus,
            $newStatus,
            $oldCategory,
            $newCategory,
            $reason,
            $source,
            data_get($context, 'request_id'),
        ]));

        TemplateStatusEvent::firstOrCreate(
            ['fingerprint' => $fingerprint],
            [
                'uuid' => (string) Str::uuid(),
                'organization_id' => $template->organization_id,
                'template_id' => $template->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_category' => $oldCategory,
                'new_category' => $newCategory,
                'reason' => $reason,
                'source' => $source,
                'context' => $context,
                'occurred_at' => now(),
            ]
        );
    }
}
