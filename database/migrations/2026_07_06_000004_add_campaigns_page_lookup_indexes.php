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
        if (! $this->indexExists('teams', 'idx_teams_org_user_deleted_role')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                ADD INDEX `idx_teams_org_user_deleted_role` (`organization_id`, `user_id`, `deleted_at`, `role`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('addons', 'idx_addons_name_status_active')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `addons`
                ADD INDEX `idx_addons_name_status_active` (`name`, `status`, `is_active`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('campaigns', 'idx_campaigns_org_deleted_created_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaigns`
                ADD INDEX `idx_campaigns_org_deleted_created_id` (`organization_id`, `deleted_at`, `created_at`, `id`),
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
        if ($this->indexExists('campaigns', 'idx_campaigns_org_deleted_created_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaigns`
                DROP INDEX `idx_campaigns_org_deleted_created_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('addons', 'idx_addons_name_status_active')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `addons`
                DROP INDEX `idx_addons_name_status_active`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('teams', 'idx_teams_org_user_deleted_role')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `teams`
                DROP INDEX `idx_teams_org_user_deleted_role`,
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
