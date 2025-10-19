<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Chat;
use App\Models\ChatMedia;
use Illuminate\Support\Facades\Storage;

class CheckChatMedia extends Command
{
    protected $signature = 'cbdash:check-media {chat_id : The chat ID to check}';

    protected $description = 'Check if media exists for a specific chat';

    public function handle()
    {
        $chatId = $this->argument('chat_id');

        $this->info("Checking media for Chat ID: {$chatId}");
        $this->info('-----------------------------------');
        $this->newLine();

        // Check if chat exists
        $chat = Chat::find($chatId);
        
        if (!$chat) {
            $this->error("Chat not found with ID: {$chatId}");
            return Command::FAILURE;
        }

        $this->info("✓ Chat exists");
        $this->line("  Created at: {$chat->created_at}");
        $this->line("  Media ID: " . ($chat->media_id ?? 'NULL'));
        $this->newLine();

        // Check media_id
        if (!$chat->media_id) {
            $this->warn("✗ No media attached to this chat");
            return Command::SUCCESS;
        }

        // Check media record
        $media = ChatMedia::find($chat->media_id);
        
        if (!$media) {
            $this->error("✗ Media record not found with ID: {$chat->media_id}");
            return Command::SUCCESS;
        }

        $this->info("✓ Media record exists");
        $this->line("  Media ID: {$media->id}");
        $this->line("  Path: {$media->path}");
        $this->newLine();

        // Check physical file
        if (!$media->path) {
            $this->warn("✗ Media path is empty");
            return Command::SUCCESS;
        }

        $filename = basename($media->path);
        $fullPath = "public/{$filename}";
        
        if (Storage::disk('public')->exists($filename)) {
            $fileSize = Storage::disk('public')->size($filename);
            $this->info("✓ Physical file exists");
            $this->line("  Filename: {$filename}");
            $this->line("  Size: " . $this->formatBytes($fileSize));
            $this->line("  Full path: storage/app/{$fullPath}");
        } else {
            $this->error("✗ Physical file not found");
            $this->line("  Expected: storage/app/{$fullPath}");
        }

        $this->newLine();
        $this->info('Check complete');

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}