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
        if (!$this->indexExists('chat_tickets', 'idx_chat_tickets_org_status_assigned')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chat_tickets`
                ADD INDEX `idx_chat_tickets_org_status_assigned` (`organization_id`, `status`, `assigned_to`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (!$this->indexExists('teams', 'idx_teams_org_deleted_user')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                ADD INDEX `idx_teams_org_deleted_user` (`organization_id`, `deleted_at`, `user_id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (!$this->indexExists('teams', 'idx_teams_user_deleted_org')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                ADD INDEX `idx_teams_user_deleted_org` (`user_id`, `deleted_at`, `organization_id`),
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
        if ($this->indexExists('teams', 'idx_teams_user_deleted_org')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                DROP INDEX `idx_teams_user_deleted_org`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('teams', 'idx_teams_org_deleted_user')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                DROP INDEX `idx_teams_org_deleted_user`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('chat_tickets', 'idx_chat_tickets_org_status_assigned')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chat_tickets`
                DROP INDEX `idx_chat_tickets_org_status_assigned`,
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
