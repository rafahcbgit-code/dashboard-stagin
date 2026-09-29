<?php

namespace App\Console\Commands;

use App\Jobs\DeliverNotificationJob;
use App\Jobs\ResolveNotificationEventJob;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileNotifications extends Command
{
    protected $signature = 'notifications:reconcile';
    protected $description = 'Queue due notification events and deliveries and recover interrupted deliveries';

    public function handle(): int
    {
        $connection = config('managed_notifications.queue_connection');

        NotificationDelivery::query()
            ->where('status', 'processing')
            ->where('claimed_at', '<', now()->subMinutes(5))
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'channel', 'attempts'])
            ->each(function ($delivery) {
                NotificationDelivery::query()->whereKey($delivery->id)
                    ->where('status', 'processing')
                    ->where('claimed_at', '<', now()->subMinutes(5))
                    ->update([
                        'status' => $delivery->channel === 'email' ? 'uncertain'
                            : ($delivery->attempts >= config('managed_notifications.max_attempts', 4) ? 'failed' : 'pending'),
                        'claimed_at' => null,
                        'queued_at' => null,
                        'next_attempt_at' => now(),
                        'last_error' => 'Worker stopped before delivery outcome was recorded.',
                        'updated_at' => now(),
                    ]);
            });

        NotificationEvent::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))
            ->orderBy('id')->limit(500)->pluck('id')
            ->each(function ($id) use ($connection) {
                $leasedAt = now();
                $leased = NotificationEvent::query()->whereKey($id)->where('status', 'pending')
                    ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $leasedAt))
                    ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', $leasedAt->copy()->subMinutes(5)))
                    ->update(['queued_at' => $leasedAt, 'updated_at' => $leasedAt]);
                if (! $leased) {
                    return;
                }
                try {
                    ResolveNotificationEventJob::dispatch((int) $id)
                        ->onConnection($connection)
                        ->onQueue(config('managed_notifications.queues.resolve'));
                } catch (Throwable $exception) {
                    NotificationEvent::query()->whereKey($id)->where('status', 'pending')
                        ->where('queued_at', $leasedAt)->update(['queued_at' => null, 'updated_at' => now()]);
                    Log::error('Notification resolver dispatch failed', ['event_id' => $id, 'error' => $exception->getMessage()]);
                }
            });

        NotificationDelivery::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', now()->subMinutes(5)))
            ->orderBy('id')->limit(500)->get(['id', 'channel'])
            ->each(function ($delivery) use ($connection) {
                $leasedAt = now();
                $leased = NotificationDelivery::query()->whereKey($delivery->id)->where('status', 'pending')
                    ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $leasedAt))
                    ->where(fn ($query) => $query->whereNull('queued_at')->orWhere('queued_at', '<', $leasedAt->copy()->subMinutes(5)))
                    ->update(['queued_at' => $leasedAt, 'updated_at' => $leasedAt]);
                if (! $leased) {
                    return;
                }
                try {
                    DeliverNotificationJob::dispatch((int) $delivery->id)
                        ->onConnection($connection)
                        ->onQueue(config('managed_notifications.queues.'.$delivery->channel, 'default'));
                } catch (Throwable $exception) {
                    NotificationDelivery::query()->whereKey($delivery->id)->where('status', 'pending')
                        ->where('queued_at', $leasedAt)->update(['queued_at' => null, 'updated_at' => now()]);
                    Log::error('Notification delivery dispatch failed', ['delivery_id' => $delivery->id, 'error' => $exception->getMessage()]);
                }
            });

        return self::SUCCESS;
    }
}
