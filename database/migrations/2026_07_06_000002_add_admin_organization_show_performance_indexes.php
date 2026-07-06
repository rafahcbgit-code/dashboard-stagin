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
        if (! $this->indexExists('subscriptions', 'idx_subscriptions_org_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                ADD INDEX `idx_subscriptions_org_id` (`organization_id`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('users', 'idx_users_role_deleted_created_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `users`
                ADD INDEX `idx_users_role_deleted_created_id` (`role`, `deleted_at`, `created_at`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('teams', 'idx_teams_org_deleted_user_role')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                ADD INDEX `idx_teams_org_deleted_user_role` (`organization_id`, `deleted_at`, `user_id`, `role`),
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
        if ($this->indexExists('teams', 'idx_teams_org_deleted_user_role')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                DROP INDEX `idx_teams_org_deleted_user_role`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('users', 'idx_users_role_deleted_created_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `users`
                DROP INDEX `idx_users_role_deleted_created_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('subscriptions', 'idx_subscriptions_org_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                DROP INDEX `idx_subscriptions_org_id`,
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
