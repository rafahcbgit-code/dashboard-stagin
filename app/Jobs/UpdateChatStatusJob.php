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

    private $wamId;
    private $status;
    private $organizationId;
    private $webhookPayload;

    public $tries = 5; // Maximum retry attempts
    public $timeout = 60; // Job timeout
    public $backoff = [10, 30, 60, 120, 300]; // Exponential backoff in seconds

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

        Log::channel('webhook')->info('JOB webhook statuses', [
            'chatWamId' => $this->wamId,
            'status' => $this->status,
            'chat_id' => $chat ? $chat->id : null,
            'attempt' => $this->attempts()
        ]);

        if (!$chat) {
            // Throw an exception to trigger Laravel's retry mechanism
            throw new \Exception("Chat not found for wam_id: {$this->wamId}");
        }

        if (!in_array($this->status, ['delivered', 'read'])) {
            // Update chat
            $chat->update(['status' => $this->status]);

            // Log status
            ChatStatusLog::create([
                'chat_id' => $chat->id,
                'metadata' => json_encode($this->webhookPayload),
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::channel('webhook')->error('UpdateChatStatusJob failed permanently', [
            'wam_id' => $this->wamId,
            'status' => $this->status,
            'organization_id' => $this->organizationId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}