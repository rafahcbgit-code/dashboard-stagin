<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatStatusLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateChatStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 5;

    // Retry delays in seconds (3s, 6s, 12s, 24s)
    public $backoff = [3, 6, 12, 24];

    private $wamId;
    private $status;
    private $organizationId;
    private $webhookPayload;

    public function __construct($wamId, $status, $organizationId, $webhookPayload)
    {
        $this->wamId = $wamId;
        $this->status = $status;
        $this->organizationId = $organizationId;
        $this->webhookPayload = $webhookPayload;
    }

    public function handle()
    {
        $chat = Chat::where('wam_id', $this->wamId)
                   ->where('organization_id', $this->organizationId)
                   ->first();

        if ($chat) {
            $chat->status = $this->status;
            $chat->save();

            // Create status log
            $chatStatusLog = new ChatStatusLog;
            $chatStatusLog->chat_id = $chat->id;
            $chatStatusLog->metadata = json_encode($this->webhookPayload);
            $chatStatusLog->save();

            Log::channel('webhook')->info('Delayed status update successful', [
                'wam_id' => $this->wamId,
                'chat_id' => $chat->id,
                'status' => $this->status,
                'organization_id' => $this->organizationId
            ]);
        } else {
            Log::channel('webhook')->warning('Chat not found, will retry', [
                'wam_id' => $this->wamId,
                'status' => $this->status,
                'organization_id' => $this->organizationId,
                'attempt' => $this->attempts()
            ]);

            // Trigger retry by throwing exception
            throw new \Exception('Chat not found yet');
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::channel('webhook')->error('UpdateChatStatusJob failed permanently', [
            'wam_id' => $this->wamId,
            'status' => $this->status,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage()
        ]);
    }
}
