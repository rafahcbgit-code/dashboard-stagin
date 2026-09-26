<?php

namespace App\Jobs;

use App\Services\ManagedNotifications\NotificationResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResolveNotificationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly int $eventId)
    {
    }

    public function handle(NotificationResolver $resolver): void
    {
        $resolver->resolve($this->eventId);
    }
}
