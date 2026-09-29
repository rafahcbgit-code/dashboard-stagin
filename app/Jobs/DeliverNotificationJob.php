<?php

namespace App\Jobs;

use App\Services\ManagedNotifications\NotificationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId)
    {
    }

    public function handle(NotificationDispatcher $dispatcher): void
    {
        $dispatcher->deliver($this->deliveryId);
    }
}
