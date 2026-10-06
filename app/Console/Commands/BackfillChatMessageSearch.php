<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\ChatArchive;
use App\Services\ChatMessageSearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class BackfillChatMessageSearch extends Command
{
    protected $signature = 'chat-search:backfill {--batch=500 : Rows per batch} {--limit=0 : Maximum document batches for this run} {--reset : Rebuild the index from the start} {--prune : Also remove orphaned index rows}';
    protected $description = 'Index existing and archived conversation messages for in-chat search';

    public function handle(ChatMessageSearchService $search): int
    {
        if (! Schema::hasTable('chat_message_search') || ! Schema::hasTable('chat_search_index_state')) {
            $this->error('Run migrations before backfilling chat search.');
            return self::FAILURE;
        }

        $batch = max(1, min(2000, (int) $this->option('batch')));
        $limit = max(0, (int) $this->option('limit'));
        $processedBatches = 0;
        DB::table('chat_search_index_state')->insertOrIgnore(['version' => 2]);
        if ($this->option('reset')) {
            DB::table('chat_search_index_state')->where('version', 2)->update([
                'completed_at' => null,
                'live_last_id' => 0,
                'archive_last_id' => 0,
                'revocations_scanned_at' => null,
                'live_done' => false,
                'archive_done' => false,
            ]);
            Cache::forget('chat-search-index-ready-v2');
        }
        $state = DB::table('chat_search_index_state')->where('version', 2)->first();

        // Revoke events may be newer than their original messages. Gather their
        // tombstones before resuming a document pass from any checkpoint.
        if (! $state->revocations_scanned_at) {
            foreach ([Chat::class, ChatArchive::class] as $model) {
                if ($model === ChatArchive::class && ! Schema::hasTable('chats_archive')) continue;
                $model::query()->where('metadata', 'like', '%"type":"revoke"%')
                    ->select(['id', 'organization_id', 'contact_id', 'wam_id', 'type', 'metadata', 'deleted_at', 'created_at'])
                    ->chunkById($batch, fn ($rows) => $search->indexBatch($rows));
            }
            DB::table('chat_search_index_state')->where('version', 2)
                ->update(['revocations_scanned_at' => now()]);
        }

        foreach ([Chat::class, ChatArchive::class] as $model) {
            $archive = $model === ChatArchive::class;
            $checkpoint = $archive ? 'archive_last_id' : 'live_last_id';
            $done = $archive ? 'archive_done' : 'live_done';
            if ($archive && ! Schema::hasTable('chats_archive')) {
                DB::table('chat_search_index_state')->where('version', 2)->update([$done => true]);
                continue;
            }
            $state = DB::table('chat_search_index_state')->where('version', 2)->first();
            if ($state->{$done}) continue;
            $interrupted = false;
            $model::query()->select(['id', 'organization_id', 'contact_id', 'type', 'metadata', 'deleted_at', 'created_at'])
                ->addSelect('wam_id')->where('id', '>', $state->{$checkpoint})
                ->chunkById($batch, function ($messages) use ($search, $model, $checkpoint, $limit, &$processedBatches, &$interrupted) {
                    $search->indexBatch($messages);
                    $processedBatches++;
                    $this->line(($model === Chat::class ? 'live' : 'archive') . ' through id ' . $messages->last()->id);
                    DB::table('chat_search_index_state')->where('version', 2)
                        ->update([$checkpoint => $messages->last()->id]);
                    if ($limit && $processedBatches >= $limit) {
                        $interrupted = true;
                        return false;
                    }
                    return true;
                });
            if (! $interrupted) DB::table('chat_search_index_state')->where('version', 2)->update([$done => true]);
            if ($limit && $processedBatches >= $limit) break;
        }

        $state = DB::table('chat_search_index_state')->where('version', 2)->first();
        if ($state->live_done && $state->archive_done && ($this->option('prune') || ! $state->completed_at)) {
            DB::table('chat_message_search')->orderBy('chat_id')->chunkById($batch, function ($rows) {
                $ids = $rows->pluck('chat_id')->all();
                $live = Chat::query()->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->all();
                $archive = ChatArchive::query()->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->all();
                $valid = array_flip(array_merge($live, $archive));
                $orphaned = array_values(array_filter($ids, fn ($id) => ! isset($valid[$id])));
                if ($orphaned) DB::table('chat_message_search')->whereIn('chat_id', $orphaned)->delete();
            }, 'chat_id');
        }

        if ($state->live_done && $state->archive_done) {
            DB::table('chat_search_index_state')->updateOrInsert(
                ['version' => 2], ['completed_at' => now()]
            );
            Cache::forget('chat-search-index-ready-v2');
            $this->info('Conversation search index is up to date.');
        } else {
            $this->info('Backfill checkpoint saved. Run this command again to continue.');
        }
        return self::SUCCESS;
    }
}
