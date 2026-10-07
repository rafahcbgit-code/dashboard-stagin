<?php

namespace App\Services\Meta\WhatsApp;

use App\Models\Organization;
use App\Models\Template;
use App\Services\ManagedNotifications\NotificationEventRecorder;

class TemplateStatusNotificationRecorder
{
    public function __construct(private readonly NotificationEventRecorder $events)
    {
    }

    public function record(Template $template, Organization $organization, string $oldStatus, string $newStatus): void
    {
        $type = match (strtoupper($newStatus)) {
            'APPROVED' => 'meta.template_approved',
            'REJECTED' => 'meta.template_rejected',
            'PAUSED' => 'meta.template_paused',
            'DISABLED' => 'meta.template_disabled',
            default => null,
        };

        if ($type === null || strtoupper($oldStatus) === strtoupper($newStatus)) {
            return;
        }

        $this->events->record(
            $organization->id,
            $type,
            'meta-template:'.$template->id.':'.$template->notification_transition_sequence.':'.$oldStatus.':'.$newStatus,
            ['organization_name' => $organization->name, 'template_name' => $template->name]
        );
    }

    public function recordCategoryChange(Template $template, Organization $organization, string $oldCategory, string $newCategory): void
    {
        if (strtoupper($oldCategory) === strtoupper($newCategory)) {
            return;
        }

        $this->events->record(
            $organization->id,
            'meta.template_category_changed',
            'meta-template-category:'.$template->id.':'.$oldCategory.':'.$newCategory,
            [
                'organization_name' => $organization->name,
                'template_name' => $template->name,
                'old_category' => $oldCategory,
                'new_category' => $newCategory,
            ]
        );
    }
}
