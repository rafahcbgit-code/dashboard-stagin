<?php

namespace App\Services\ManagedNotifications\Channels;

use App\Models\NotificationEvent;
use App\Models\NotificationLog;

class DashboardChannel
{
    /** Called within the resolver transaction, so the log and delivery commit together. */
    public function write(NotificationEvent $event, int $userId, string $message): NotificationLog
    {
        return NotificationLog::create([
            'user_id' => $userId,
            'organization_id' => $event->organization_id,
            'notification_type' => $event->type_key,
            'payload' => ['message' => $message, 'event_id' => $event->id],
            'delivered' => true,
        ]);
    }
}
