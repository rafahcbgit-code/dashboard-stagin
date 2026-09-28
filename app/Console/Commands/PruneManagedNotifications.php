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

        NotificationLog::query()->select('id')
            ->whereIn('notification_type', $types)
            ->where('clicked', true)
            ->where('sent_at', '<', $cutoff)
            ->chunkById(500, function ($logs): void {
                NotificationLog::query()->whereIn('id', $logs->pluck('id'))->delete();
            });

        NotificationEvent::query()->select('id')
            ->whereIn('status', ['resolved', 'canceled'])
            ->where('created_at', '<', $cutoff)
            ->when(Schema::hasTable('subscriptions'), function ($events): void {
                // Keep usage identities for the whole active period, including annual plans.
                $events->where(function ($query): void {
                    $query->where('type_key', '!=', 'subscription.resource_warning')
                        ->orWhereNotExists(function ($period): void {
                            $period->selectRaw('1')->from('subscriptions as current_period')
                                ->whereColumn('current_period.organization_id', 'notification_events.organization_id')
                                ->whereIn('current_period.status', ['active', 'trial'])
                                ->where('current_period.valid_until', '>', now())
                                ->whereColumn('notification_events.created_at', '>=', DB::raw('COALESCE(current_period.start_date, current_period.created_at)'))
                                ->where('current_period.id', '=', function ($latest): void {
                                    $latest->select('latest.id')->from('subscriptions as latest')
                                        ->whereColumn('latest.organization_id', 'current_period.organization_id')
                                        ->orderByDesc('latest.id')->limit(1);
                                });
                        });
                });
            })
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
            ->chunkById(500, function ($events): void {
                DB::table('notification_events')->whereIn('id', $events->pluck('id'))->delete();
            });

        if (Schema::hasTable('subscription_plan_notification_changes')) {
            DB::table('subscription_plan_notification_changes')->select('id')
                ->whereNotNull('processed_at')->where('processed_at', '<', $cutoff)
                ->chunkById(500, function ($changes): void {
                    DB::table('subscription_plan_notification_changes')->whereIn('id', $changes->pluck('id'))->delete();
                });
        }

        return self::SUCCESS;
    }
}
