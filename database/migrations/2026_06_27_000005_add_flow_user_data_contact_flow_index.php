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
        if (!$this->indexExists('flow_user_data', 'idx_flow_user_data_contact_flow')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `flow_user_data`
                ADD INDEX `idx_flow_user_data_contact_flow` (`contact_id`, `flow_id`),
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
        if ($this->indexExists('flow_user_data', 'idx_flow_user_data_contact_flow')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `flow_user_data`
                DROP INDEX `idx_flow_user_data_contact_flow`,
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
