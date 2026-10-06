<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Services\ChatMessageSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RepairChatSearchIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public function __construct(public int $chatId)
    {
    }

    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(ChatMessageSearchService $search): void
    {
        $chat = Chat::query()->find($this->chatId);
        if ($chat) {
            $search->index($chat);
        }
    }
}
