<?php

namespace App\Services\ManagedNotifications;

use App\Models\NotificationEvent;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class NotificationEventRecorder
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly NotificationTemplateRenderer $renderer,
    ) {
    }

    public function hasRecorded(int $organizationId, string $typeKey, string $sourceKey): bool
    {
        return NotificationEvent::query()->where('organization_id', $organizationId)
            ->where('type_key', $typeKey)->where('event_key', hash('sha256', $sourceKey))->exists();
    }

    /**
     * Call this inside the source state transaction to make the outbox atomic.
     * The scheduled reconciler queues all pending rows; no network I/O occurs here.
     */
    public function record(int $organizationId, string $typeKey, string $sourceKey, array $variables): NotificationEvent
    {
        $type = $this->catalog->get($typeKey);
        $this->renderer->validateValues($variables, $type['variables']);
        ksort($variables);

        if ($sourceKey === '' || strlen($sourceKey) > 512) {
            throw new InvalidArgumentException('A stable source key is required.');
        }

        if (! Organization::query()->whereKey($organizationId)->whereNull('deleted_at')->exists()) {
            throw new InvalidArgumentException('Notification organization does not exist.');
        }

        $eventKey = hash('sha256', $sourceKey);

        return DB::transaction(function () use ($organizationId, $typeKey, $eventKey, $variables) {
            DB::table('notification_events')->insertOrIgnore([
                'organization_id' => $organizationId,
                'type_key' => $typeKey,
                'event_key' => $eventKey,
                'variables' => json_encode($variables, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $event = NotificationEvent::query()
                ->where('organization_id', $organizationId)
                ->where('type_key', $typeKey)
                ->where('event_key', $eventKey)
                ->firstOrFail();

            if ($event->variables !== $variables) {
                throw new InvalidArgumentException('Source key was reused with different notification data.');
            }

            return $event;
        });
    }
}
