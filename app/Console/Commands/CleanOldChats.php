<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Chat;
use App\Models\ChatMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanOldChats extends Command
{
    protected $signature = 'cbdash:clean-old-chats 
                            {--batch-size=500 : Number of records per batch}
                            {--sleep=0.5 : Sleep duration between batches in seconds}
                            {--limit= : Limit number of records to process (for testing)}';

    protected $description = 'Delete chats older than 2 weeks along with associated data';

    private int $totalDeleted = 0;
    private int $totalErrors = 0;

    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        $sleepTime = (float) $this->option('sleep');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $cutoffDate = Carbon::now()->subWeeks(2);

        $this->displayStartInfo($cutoffDate, $batchSize, $sleepTime, $limit);

        $query = Chat::where('created_at', '<', $cutoffDate);
        
        $totalRecords = $limit ? min($limit, $query->count()) : $query->count();
        
        if ($totalRecords === 0) {
            $this->info('No records found to clean up.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalRecords} chats to process.");
        
        if ($limit) {
            $this->warn("LIMIT MODE: Processing only {$limit} records");
        }
        
        if (!$this->confirm('Do you want to continue?', true)) {
            $this->info('Operation cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $progressBar = $this->output->createProgressBar($totalRecords);
        $progressBar->start();

        try {
            $processedCount = 0;
            
            Chat::where('created_at', '<', $cutoffDate)
                ->orderBy('id')
                ->chunk($batchSize, function ($chats) use ($progressBar, $sleepTime, &$processedCount, $limit) {
                    foreach ($chats as $chat) {
                        if ($limit && $processedCount >= $limit) {
                            return false;
                        }
                        
                        $this->processChat($chat);
                        $progressBar->advance();
                        $processedCount++;
                    }
                    usleep($sleepTime * 1000000);
                });

            $progressBar->finish();
            $this->newLine(2);
            $this->displaySummary();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine(2);
            $this->error("Fatal error: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    private function processChat(Chat $chat): void
    {
        try {
            DB::beginTransaction();

            $this->deleteRelatedRecords($chat->id);
            $this->deleteMedia($chat->media_id);
            
            $chat->delete();
            
            DB::commit();
            $this->totalDeleted++;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->totalErrors++;
            \Log::error("Failed to delete chat {$chat->id}: {$e->getMessage()}");
        }
    }

    private function deleteRelatedRecords(int $chatId): void
    {
        DB::table('chat_status_logs')->where('chat_id', $chatId)->delete();
        
        DB::table('chat_logs')
            ->where('entity_type', 'chat')
            ->where('entity_id', $chatId)
            ->delete();
    }

    private function deleteMedia(?int $mediaId): void
    {
        if (!$mediaId) {
            return;
        }

        try {
            $media = ChatMedia::find($mediaId);
            
            if (!$media || !$media->path) {
                return;
            }

            $filename = basename($media->path);
            
            if (Storage::disk('public')->exists($filename)) {
                Storage::disk('public')->delete($filename);
            }
            
            $media->delete();

        } catch (\Exception $e) {
            \Log::warning("Failed to delete media {$mediaId}: {$e->getMessage()}");
        }
    }

    private function displayStartInfo(Carbon $cutoffDate, int $batchSize, float $sleepTime, ?int $limit): void
    {
        $this->info('Chat Cleanup Process');
        $this->info('-------------------');
        $this->info("Cutoff date: {$cutoffDate->format('Y-m-d H:i:s')}");
        $this->info("Batch size: {$batchSize}");
        $this->info("Sleep time: {$sleepTime}s");
        if ($limit) {
            $this->info("Limit: {$limit} records");
        }
        $this->newLine();
    }

    private function displaySummary(): void
    {
        $this->info('Cleanup Summary');
        $this->info('---------------');
        $this->info("Successfully deleted: {$this->totalDeleted}");
        
        if ($this->totalErrors > 0) {
            $this->warn("Failed deletions: {$this->totalErrors}");
            $this->warn('Check logs for details.');
        }
    }
}