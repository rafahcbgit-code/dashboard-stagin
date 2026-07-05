<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!$this->indexExists('chat_logs', 'idx_chat_logs_entity_lookup')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chat_logs`
                ADD INDEX `idx_chat_logs_entity_lookup` (`entity_type`, `entity_id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->indexExists('chat_logs', 'idx_chat_logs_entity_lookup')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chat_logs`
                DROP INDEX `idx_chat_logs_entity_lookup`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    /**
     * Check if an index exists on a given table.
     */
    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
