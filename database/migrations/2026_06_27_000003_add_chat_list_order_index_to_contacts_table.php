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
        if (!$this->indexExists('contacts', 'idx_contacts_chat_list_order')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                ADD INDEX `idx_contacts_chat_list_order` (`organization_id`, `deleted_at`, `latest_chat_created_at`),
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
        if ($this->indexExists('contacts', 'idx_contacts_chat_list_order')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `contacts`
                DROP INDEX `idx_contacts_chat_list_order`,
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
