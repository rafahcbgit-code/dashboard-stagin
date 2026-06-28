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
        if ($this->indexExists('chats', 'idx_organization_id') && $this->hasOrganizationPrefixIndex()) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                DROP INDEX `idx_organization_id`,
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
        if (!$this->indexExists('chats', 'idx_organization_id')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `idx_organization_id` (`organization_id`),
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

    protected function hasOrganizationPrefixIndex(): bool
    {
        $indexes = DB::select(<<<'SQL'
            SHOW INDEXES FROM `chats`
            WHERE Seq_in_index = 1
              AND Column_name = 'organization_id'
              AND Key_name <> 'idx_organization_id'
        SQL);

        return count($indexes) > 0;
    }
};
