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

    public $backoff = [2, 5, 10]; // Progressive delay: 2s, 5s, 10s


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
            Log::channel('webhook')->warning('Chat still not found after delayed retry', [
                'wam_id' => $this->wamId,
                'status' => $this->status,
                'organization_id' => $this->organizationId,
                'attempt' => $this->attempts()
            ]);

            // If this is not the last attempt, throw exception to retry
            if ($this->attempts() < $this->tries) {
                throw new \Exception('Chat not found, will retry');
            }
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