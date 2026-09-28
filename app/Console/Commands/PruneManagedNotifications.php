<?php

namespace App\Console\Commands;

use App\Models\NotificationEvent;
use App\Models\NotificationLog;
use App\Services\ManagedNotifications\NotificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneManagedNotifications extends Command
{
    protected $signature = 'notifications:prune';
    protected $description = 'Prune old completed managed notifications while retaining failures and unread dashboard items';

    public function handle(NotificationCatalog $catalog): int
    {
        $days = max(30, (int) config('managed_notifications.retention_days', 180));
        $cutoff = now()->subDays($days);
        $types = array_keys($catalog->all());

        while (true) {
            $ids = NotificationLog::query()
                ->whereIn('notification_type', $types)
                ->where('clicked', true)
                ->where('sent_at', '<', $cutoff)
                ->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            NotificationLog::query()->whereIn('id', $ids)->delete();
        }

        while (true) {
            $ids = NotificationEvent::query()
                ->whereIn('status', ['resolved', 'canceled'])
                ->where('created_at', '<', $cutoff)
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('notification_deliveries as d')
                        ->whereColumn('d.event_id', 'notification_events.id')
                        ->whereNotIn('d.status', ['sent', 'canceled']);
                })
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')->from('notification_deliveries as d')
                        ->join('notification_logs as n', 'n.id', '=', 'd.notification_log_id')
                        ->whereColumn('d.event_id', 'notification_events.id')
                        ->where('d.channel', 'dashboard')
                        ->where('n.clicked', false);
                })
                ->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            DB::table('notification_events')->whereIn('id', $ids)->delete();
        }

        if (Schema::hasTable('subscription_plan_notification_changes')) {
            while (true) {
                $ids = DB::table('subscription_plan_notification_changes')
                    ->whereNotNull('processed_at')->where('processed_at', '<', $cutoff)
                    ->orderBy('id')->limit(500)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                DB::table('subscription_plan_notification_changes')->whereIn('id', $ids)->delete();
            }
        }

        return self::SUCCESS;
    }
}
