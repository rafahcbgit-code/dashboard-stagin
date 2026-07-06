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
        if (! $this->indexExists('notification_logs', 'idx_notification_logs_unread_count')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `notification_logs`
                ADD INDEX `idx_notification_logs_unread_count` (`user_id`, `organization_id`, `notification_type`, `clicked`),
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
        if ($this->indexExists('notification_logs', 'idx_notification_logs_unread_count')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `notification_logs`
                DROP INDEX `idx_notification_logs_unread_count`,
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
