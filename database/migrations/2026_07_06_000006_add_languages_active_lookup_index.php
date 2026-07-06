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
        if (! $this->indexExists('languages', 'idx_languages_deleted_status_id_code')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `languages`
                ADD INDEX `idx_languages_deleted_status_id_code` (`deleted_at`, `status`, `id`, `code`),
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
        if ($this->indexExists('languages', 'idx_languages_deleted_status_id_code')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `languages`
                DROP INDEX `idx_languages_deleted_status_id_code`,
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
