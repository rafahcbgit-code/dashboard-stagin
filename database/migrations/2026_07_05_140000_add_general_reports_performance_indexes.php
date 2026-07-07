<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'contacts',
            'idx_contacts_reports_org_deleted_created_subscribed',
            'ADD INDEX `idx_contacts_reports_org_deleted_created_subscribed` (`organization_id`, `deleted_at`, `created_at`, `is_subscribed`)'
        );

        $this->addIndexIfMissing(
            'chats',
            'idx_chats_reports_org_deleted_created_type',
            'ADD INDEX `idx_chats_reports_org_deleted_created_type` (`organization_id`, `deleted_at`, `created_at`, `type`)'
        );

        $this->addIndexIfMissing(
            'chat_tickets',
            'idx_chat_tickets_reports_org_created_status_assigned',
            'ADD INDEX `idx_chat_tickets_reports_org_created_status_assigned` (`organization_id`, `created_at`, `status`, `assigned_to`)'
        );

        $this->addIndexIfMissing(
            'contact_label',
            'idx_contact_label_label_created_contact',
            'ADD INDEX `idx_contact_label_label_created_contact` (`label_id`, `created_at`, `contact_id`)'
        );

        $this->addIndexIfMissing(
            'contact_label',
            'idx_contact_label_created_label_contact',
            'ADD INDEX `idx_contact_label_created_label_contact` (`created_at`, `label_id`, `contact_id`)'
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('contact_label', 'idx_contact_label_created_label_contact');
        $this->dropIndexIfExists('contact_label', 'idx_contact_label_label_created_contact');
        $this->dropIndexIfExists('chat_tickets', 'idx_chat_tickets_reports_org_created_status_assigned');
        $this->dropIndexIfExists('chats', 'idx_chats_reports_org_deleted_created_type');
        $this->dropIndexIfExists('contacts', 'idx_contacts_reports_org_deleted_created_subscribed');
    }

    private function addIndexIfMissing(string $table, string $index, string $definition): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        DB::statement(<<<SQL
            ALTER TABLE `{$table}`
            {$definition},
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $index)) {
            return;
        }

        DB::statement(<<<SQL
            ALTER TABLE `{$table}`
            DROP INDEX `{$index}`,
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
