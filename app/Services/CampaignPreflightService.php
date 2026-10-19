<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Template;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class CampaignPreflightService
{
    public function evaluate(int $organizationId, string $templateUuid, string $audience, ?Carbon $scheduledAt = null, array $dynamicFields = []): array
    {
        $organization = Organization::query()->find($organizationId);
        if (! $organization) {
            throw ValidationException::withMessages(['campaign' => __('Organization not found.')]);
        }

        $metadata = json_decode($organization->metadata ?? '{}', true);
        $metadata = is_array($metadata) ? $metadata : [];
        $template = Template::query()
            ->where('uuid', $templateUuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->first();

        if (! $template) {
            throw ValidationException::withMessages(['template' => __('Selected template is invalid or does not belong to your organization.')]);
        }

        $readiness = (new CampaignReadinessService())->check(
            $metadata,
            $template->status,
            (string) $template->category,
            (bool) $template->has_unsubscribe_action
        );
        $audiencePreview = (new CampaignAudienceService())->preview($organizationId, $audience, $dynamicFields);
        $checks = [
            $this->check(
                'number',
                __('WhatsApp number'),
                __('Connected'),
                $readiness['number_ready'],
                collect($readiness['warnings'])->contains(fn ($warning) => $warning['key'] === CampaignReadinessService::KEY_NUMBER_UNKNOWN),
                '/settings/whatsapp',
                __('Reconnect number')
            ),
            $this->check('template', __('Approved template'), $template->name, $readiness['template_ready'], false, '/templates', __('Choose another template')),
            $this->check('variables', __('Template variables'), __('Required values are provided'), true, false),
            $this->check('audience', __('Eligible recipients'), (string) $audiencePreview['recipient_count'], $audiencePreview['recipient_count'] > 0, false),
            $this->check(
                'unsubscribe',
                __('Unsubscribe option'),
                $readiness['unsubscribe_ready']
                    ? __('Marketing template includes an unsubscribe button')
                    : __('This marketing template has no unsubscribe button. You can still send the campaign, but adding one helps recipients opt out.'),
                $readiness['unsubscribe_ready'],
                ! $readiness['unsubscribe_ready'],
                '/templates',
                __('Add unsubscribe button')
            ),
        ];

        foreach ($readiness['warnings'] as $warning) {
            if ($warning['key'] === 'unsubscribe_missing') {
                continue;
            }

            $checks[] = [
                'key' => $warning['key'],
                'label' => __('Number quality'),
                'detail' => $warning['message'],
                'status' => 'warning',
                'action' => $warning['action'],
            ];
        }

        if (strtoupper((string) $template->category) === 'MARKETING') {
            $availableAt = $this->nextMarketingSendTime($organizationId);
            $target = $scheduledAt ?? now('UTC');
            $intervalReady = ! $availableAt || $target->greaterThanOrEqualTo($availableAt);
            $intervalCheck = $this->check(
                'marketing_interval',
                __('Marketing campaign spacing'),
                $intervalReady ? __('At least 24 hours since the previous marketing campaign') : __('Available after :time', ['time' => $availableAt->timezone($metadata['timezone'] ?? 'UTC')->format('Y-m-d H:i')]),
                $intervalReady,
                false,
                null,
                null
            );
            $intervalCheck['schedule_after'] = $intervalReady
                ? null
                : $availableAt->timezone($metadata['timezone'] ?? 'UTC')->format('Y-m-d\\TH:i');
            $intervalCheck['action'] = $intervalReady ? null : [
                'label' => __('Schedule after allowed time'),
                'type' => 'schedule_after',
            ];
            $checks[] = $intervalCheck;
        }

        return [
            'ready' => collect($checks)->every(fn ($check) => $check['status'] !== 'blocked'),
            'checks' => $checks,
            'warnings' => $readiness['warnings'],
            'audience' => $audiencePreview,
            'template' => [
                'name' => $template->name,
                'category' => $template->category,
                'status' => $template->status,
                'has_unsubscribe_action' => (bool) $template->has_unsubscribe_action,
            ],
            'scheduled_at' => ($scheduledAt ?? now('UTC'))->toIso8601String(),
        ];
    }

    private function nextMarketingSendTime(int $organizationId): ?Carbon
    {
        $lastStartedAt = Campaign::query()
            ->join('templates', 'templates.id', '=', 'campaigns.template_id')
            ->where('campaigns.organization_id', $organizationId)
            ->whereNull('campaigns.deleted_at')
            ->whereNotNull('campaigns.started_at')
            ->whereIn('campaigns.status', ['ongoing', 'completed', 'cancelled'])
            ->whereRaw('UPPER(templates.category) = ?', ['MARKETING'])
            ->max('campaigns.started_at');

        $lastScheduledAt = Campaign::query()
            ->join('templates', 'templates.id', '=', 'campaigns.template_id')
            ->where('campaigns.organization_id', $organizationId)
            ->whereNull('campaigns.deleted_at')
            ->where('campaigns.status', 'scheduled')
            ->whereRaw('UPPER(templates.category) = ?', ['MARKETING'])
            ->max('campaigns.scheduled_at');

        $candidates = collect([$lastStartedAt, $lastScheduledAt])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value, 'UTC')->addHours(24));

        return $candidates->isEmpty() ? null : $candidates->sort()->last();
    }

    private function check(string $key, string $label, string $detail, bool $ready, bool $warning, ?string $path = null, ?string $actionLabel = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'detail' => $detail,
            'status' => $warning ? 'warning' : ($ready ? 'ready' : 'blocked'),
            'action' => $path ? ['path' => $path, 'label' => $actionLabel] : null,
        ];
    }
}
