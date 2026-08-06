<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('contacts', 'latest_chat_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                ADD COLUMN `latest_chat_id` BIGINT UNSIGNED NULL AFTER `latest_chat_created_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->columnExists('contacts', 'latest_chat_type')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                ADD COLUMN `latest_chat_type` VARCHAR(16) NULL AFTER `latest_chat_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('contacts', 'idx_contacts_awaiting_reply')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                ADD INDEX `idx_contacts_awaiting_reply` (`organization_id`, `deleted_at`, `latest_chat_type`, `latest_chat_created_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->indexExists('contacts', 'idx_contacts_awaiting_reply')) {
            DB::statement('ALTER TABLE `contacts` DROP INDEX `idx_contacts_awaiting_reply`, ALGORITHM=INPLACE, LOCK=NONE');
        }

        if ($this->columnExists('contacts', 'latest_chat_type')) {
            DB::statement('ALTER TABLE `contacts` DROP COLUMN `latest_chat_type`, ALGORITHM=INPLACE, LOCK=NONE');
        }

        if ($this->columnExists('contacts', 'latest_chat_id')) {
            DB::statement('ALTER TABLE `contacts` DROP COLUMN `latest_chat_id`, ALGORITHM=INPLACE, LOCK=NONE');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return count(DB::select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        )) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
