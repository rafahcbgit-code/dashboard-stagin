<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class NewChatEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chat;
    public $organizationId;

    public $contact;

    /**
     * Create a new event instance.
     *
     * @param mixed $chat
     * @param int $organizationId
     * @param int $contact
     */
    public function __construct($chat, $organizationId, $contact = null)
    {
        $this->chat = $chat;
        $this->organizationId = $organizationId;
        $this->contact = $contact;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel
     */
    public function broadcastOn()
    {
        try {
            // Check if Pusher settings are available
            if (config('broadcasting.connections.pusher.key') && config('broadcasting.connections.pusher.secret')) {
                $channel = 'chats.' . 'ch' . $this->organizationId;
                return new Channel($channel);
            } else {
                // Log an error if Pusher settings are not configured
                Log::error('Pusher settings are not configured.');
                return;
            }
        } catch (Exception $e) {
            // Log the exception and prevent the event from broadcasting
            Log::error('Failed to broadcast event: ' . $e->getMessage());
            return;
        }
    }

    /**
     * Get the data to broadcast.
     * Prevents Laravel Broadcasting BroadcastException (10KB limit).
     *
     * @return array
     */
    public function broadcastWith()
    {
        // We first JSON encode and decode to ensure complete Eloquent Model evaluation to arrays
        $baseData = json_decode(json_encode([
            'chat' => $this->chat,
            'contact' => $this->contact ?? null
        ]), true);

        // Strip out base64 and excessively large strings recursively 
        // without breaking the frontend object structure.
        return $this->cleanLargePayload($baseData);
    }

    /**
     * Recursively traverse the array to purge bulky data that breaks WS limits.
     */
    private function cleanLargePayload(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = $this->cleanLargePayload($value);
            } elseif (is_string($value)) {
                // 1. Remove obvious Base64 images instantly
                if (str_starts_with($value, 'data:image/') && strpos($value, 'base64,') !== false) {
                    $value = null; 
                }
                // 2. Truncate massively long text fields (e.g., huge raw JSON metadata logs) 
                // keeping it far below the 10KB (10240 bytes) limit.
                elseif (strlen($value) > 2500) {
                    // If it is >2500 chars, it's definitely something heavy like raw API data dump
                    $value = substr($value, 0, 2500) . '...[Truncated]';
                }
            }
        }
        return $data;
    }
}
