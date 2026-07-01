<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('contacts', 'idx_contacts_org_deleted_favorite_created')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                ADD INDEX `idx_contacts_org_deleted_favorite_created` (`organization_id`, `deleted_at`, `is_favorite`, `created_at`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('contact_groups', 'idx_contact_groups_org_deleted_name')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contact_groups`
                ADD INDEX `idx_contact_groups_org_deleted_name` (`organization_id`, `deleted_at`, `name`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->indexExists('chats', 'idx_chats_contact_type_deleted_created')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `idx_chats_contact_type_deleted_created` (`contact_id`, `type`, `deleted_at`, `created_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (! $this->anyIndexExists('chats', ['idx_chats_contact_type_read_deleted', 'idx_chats_unread_opt'])) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                ADD INDEX `idx_chats_contact_type_read_deleted` (`contact_id`, `type`, `is_read`, `deleted_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    public function down(): void
    {
        if ($this->indexExists('chats', 'idx_chats_contact_type_read_deleted')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                DROP INDEX `idx_chats_contact_type_read_deleted`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('chats', 'idx_chats_contact_type_deleted_created')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `chats`
                DROP INDEX `idx_chats_contact_type_deleted_created`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('contact_groups', 'idx_contact_groups_org_deleted_name')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contact_groups`
                DROP INDEX `idx_contact_groups_org_deleted_name`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if ($this->indexExists('contacts', 'idx_contacts_org_deleted_favorite_created')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                DROP INDEX `idx_contacts_org_deleted_favorite_created`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }

    private function anyIndexExists(string $table, array $indexes): bool
    {
        foreach ($indexes as $index) {
            if ($this->indexExists($table, $index)) {
                return true;
            }
        }

        return false;
    }
};
