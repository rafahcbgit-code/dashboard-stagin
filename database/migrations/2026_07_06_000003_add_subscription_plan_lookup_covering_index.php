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
        if ($this->indexExists('subscriptions', 'idx_subscriptions_org_id')
            && ! $this->indexExists('subscriptions', 'idx_subscriptions_org_id_plan')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                DROP INDEX `idx_subscriptions_org_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('subscriptions', 'idx_subscriptions_org_id_plan')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                ADD INDEX `idx_subscriptions_org_id_plan` (`organization_id`, `id`, `plan_id`),
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
        if ($this->indexExists('subscriptions', 'idx_subscriptions_org_id_plan')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                DROP INDEX `idx_subscriptions_org_id_plan`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('subscriptions', 'idx_subscriptions_org_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `subscriptions`
                ADD INDEX `idx_subscriptions_org_id` (`organization_id`, `id`),
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
