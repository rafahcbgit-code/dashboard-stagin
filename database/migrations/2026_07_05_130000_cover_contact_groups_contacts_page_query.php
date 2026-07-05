<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('contact_groups', 'idx_contact_groups_org_deleted_name')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contact_groups`
                DROP INDEX `idx_contact_groups_org_deleted_name`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `contact_groups`
            ADD INDEX `idx_contact_groups_org_deleted_name` (`organization_id`, `deleted_at`, `name`, `id`, `uuid`, `contacts_count`),
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    public function down(): void
    {
        if ($this->indexExists('contact_groups', 'idx_contact_groups_org_deleted_name')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contact_groups`
                DROP INDEX `idx_contact_groups_org_deleted_name`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        DB::statement(<<<'SQL'
            ALTER TABLE `contact_groups`
            ADD INDEX `idx_contact_groups_org_deleted_name` (`organization_id`, `deleted_at`, `name`, `id`),
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
