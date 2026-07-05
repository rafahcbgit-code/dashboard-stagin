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
        if ($this->indexExists('chats', 'idx_wam_id') && $this->indexExists('chats', 'idx_wam_org')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                DROP INDEX `idx_wam_id`,
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
        if (!$this->indexExists('chats', 'idx_wam_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `idx_wam_id` (`wam_id`),
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
