<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('campaign_logs', 'rate_limit_error_code')
            && !$this->indexExists('campaign_logs', 'idx_campaign_logs_rate_limit_lookup')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaign_logs`
                DROP COLUMN `rate_limit_error_code`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (!Schema::hasColumn('campaign_logs', 'rate_limit_error_code')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaign_logs`
                ADD COLUMN `rate_limit_error_code` VARCHAR(64)
                GENERATED ALWAYS AS (
                    IF(
                        JSON_VALID(`metadata`),
                        COALESCE(
                            JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.data.error.code')),
                            JSON_UNQUOTE(JSON_EXTRACT(`metadata`, '$.error.code'))
                        ),
                        NULL
                    )
                ) VIRTUAL,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (!$this->indexExists('campaign_logs', 'idx_campaign_logs_rate_limit_lookup')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaign_logs`
                ADD INDEX `idx_campaign_logs_rate_limit_lookup`
                    (`campaign_id`, `rate_limit_error_code`, `status`, `chat_id`),
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
        if ($this->indexExists('campaign_logs', 'idx_campaign_logs_rate_limit_lookup')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaign_logs`
                DROP INDEX `idx_campaign_logs_rate_limit_lookup`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            SQL);
        }

        if (Schema::hasColumn('campaign_logs', 'rate_limit_error_code')) {
            DB::statement(<<<'SQL'
                ALTER TABLE `campaign_logs`
                DROP COLUMN `rate_limit_error_code`,
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
