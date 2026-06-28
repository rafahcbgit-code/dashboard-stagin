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
        if ($this->indexExists('chats', 'idx_chats_contact_org_deleted_at')
            && $this->indexExists('chats', 'idx_contact_org_created')) {
            $this->dropIndex('idx_chats_contact_org_deleted_at');
        }

        if ($this->indexExists('chats', 'chats_contact_id_index')
            && ($this->indexExists('chats', 'idx_chats_contact_created_at')
                || $this->indexExists('chats', 'idx_contact_org_created')
                || $this->indexExists('chats', 'idx_chats_unread_opt'))) {
            $this->dropIndex('chats_contact_id_index');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!$this->indexExists('chats', 'idx_chats_contact_org_deleted_at')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `idx_chats_contact_org_deleted_at` (`contact_id`, `organization_id`, `deleted_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (!$this->indexExists('chats', 'chats_contact_id_index')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `chats_contact_id_index` (`contact_id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }

    protected function dropIndex(string $index): void
    {
        DB::statement(<<<SQL
            ALTER TABLE `chats`
            DROP INDEX `{$index}`,
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }
};
