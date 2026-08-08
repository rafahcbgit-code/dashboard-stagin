<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_notes')) {
            return;
        }

        $indexes = [];

        if (! $this->indexExists('chat_notes', 'idx_chat_notes_contact_deleted_created')) {
            $indexes[] = 'ADD INDEX `idx_chat_notes_contact_deleted_created` (`contact_id`, `deleted_at`, `created_at`, `id`)';
        }

        if (! $this->indexExists('chat_notes', 'idx_chat_notes_uuid_deleted_contact')) {
            $indexes[] = 'ADD INDEX `idx_chat_notes_uuid_deleted_contact` (`uuid`, `deleted_at`, `contact_id`)';
        }

        if ($indexes !== []) {
            DB::statement(
                "ALTER TABLE `chat_notes`\n"
                . implode(",\n", $indexes)
                . ",\nALGORITHM=INPLACE,\nLOCK=NONE"
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_notes')) {
            return;
        }

        $indexes = [];

        if ($this->indexExists('chat_notes', 'idx_chat_notes_uuid_deleted_contact')) {
            $indexes[] = 'DROP INDEX `idx_chat_notes_uuid_deleted_contact`';
        }

        if ($this->indexExists('chat_notes', 'idx_chat_notes_contact_deleted_created')) {
            $indexes[] = 'DROP INDEX `idx_chat_notes_contact_deleted_created`';
        }

        if ($indexes !== []) {
            DB::statement(
                "ALTER TABLE `chat_notes`\n"
                . implode(",\n", $indexes)
                . ",\nALGORITHM=INPLACE,\nLOCK=NONE"
            );
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
