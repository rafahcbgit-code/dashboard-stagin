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

        if (!$chat) {
            Log::channel('webhook')->info('Chat Not Found', [ 'wam_id' => $this->wamId, 'status' => $this->status, 'organization_id' => $this->organizationId ]);
            return; // just exit silently if chat not found
        }

        // Update chat
        $chat->update(['status' => $this->status]);

        // Log status
        ChatStatusLog::create([
            'chat_id'   => $chat->id,
            'metadata'  => json_encode($this->webhookPayload),
        ]);
    }
}
