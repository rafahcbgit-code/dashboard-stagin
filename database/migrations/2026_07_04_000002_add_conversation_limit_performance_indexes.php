<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chats_archive') && ! $this->indexExists('chats_archive', 'idx_chats_archive_org_contact_created_id')) {
            DB::statement('ALTER TABLE `chats_archive` ADD INDEX `idx_chats_archive_org_contact_created_id` (`organization_id`, `contact_id`, `created_at`, `id`)');
        }

        if (Schema::hasTable('conversation_windows') && ! $this->indexExists('conversation_windows', 'idx_conversation_windows_org_opened')) {
            DB::statement('ALTER TABLE `conversation_windows` ADD INDEX `idx_conversation_windows_org_opened` (`organization_id`, `opened_at`)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('conversation_windows') && $this->indexExists('conversation_windows', 'idx_conversation_windows_org_opened')) {
            DB::statement('ALTER TABLE `conversation_windows` DROP INDEX `idx_conversation_windows_org_opened`');
        }

        if (Schema::hasTable('chats_archive') && $this->indexExists('chats_archive', 'idx_chats_archive_org_contact_created_id')) {
            DB::statement('ALTER TABLE `chats_archive` DROP INDEX `idx_chats_archive_org_contact_created_id`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
