<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CampaignProgressEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $campaignUuid,
        public int $total,
        public int $sent,
        public int $failed,
        public int $pending,
        public float $percentComplete,
        public ?string $estimatedCompletion = null
    ) {
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\PrivateChannel|null
     */
    public function broadcastOn()
    {
        try {
            // Check if Pusher settings are available
            if (config('broadcasting.connections.pusher.key') && config('broadcasting.connections.pusher.secret')) {
                return new PrivateChannel("campaign.{$this->campaignUuid}");
            } else {
                Log::error('Pusher settings are not configured for CampaignProgressEvent.');

                return null;
            }
        } catch (\Exception $e) {
            Log::error('Failed to broadcast CampaignProgressEvent: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'CampaignProgressEvent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'campaignUuid' => $this->campaignUuid,
            'total' => $this->total,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'pending' => $this->pending,
            'percentComplete' => $this->percentComplete,
            'estimatedCompletion' => $this->estimatedCompletion,
        ];
    }
}
