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

        /* if (!$chat) {
            self::dispatch($this->wamId, $this->status, $this->organizationId, $this->webhookPayload)
                ->delay(now()->addSeconds(5));
            return;
        } */

        // Update chat
        $chat->update(['status' => $this->status]);

        Log::channel('webhook')->info('JOB webhook statuses', [
            'chatWamId' => $this->wamId,
            'status' => $this->status,
            "chat_id" => $chat ? $chat->id : null
        ]);

        // Log status
        ChatStatusLog::create([
            'chat_id'   => $chat->id,
            'metadata'  => json_encode($this->webhookPayload),
        ]);
    }
}
