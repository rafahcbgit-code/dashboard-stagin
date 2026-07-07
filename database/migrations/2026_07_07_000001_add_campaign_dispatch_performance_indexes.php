<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('campaigns', 'idx_campaigns_org_status_id')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD INDEX `idx_campaigns_org_status_id` (`organization_id`, `status`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (! $this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_status_id')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                ADD INDEX `idx_campaign_logs_campaign_status_id` (`campaign_id`, `status`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (! $this->indexExists('campaign_log_retries', 'idx_campaign_log_retries_log_id')) {
            DB::statement('
                ALTER TABLE `campaign_log_retries`
                ADD INDEX `idx_campaign_log_retries_log_id` (`campaign_log_id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('campaign_log_retries', 'idx_campaign_log_retries_log_id')) {
            DB::statement('
                ALTER TABLE `campaign_log_retries`
                DROP INDEX `idx_campaign_log_retries_log_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_status_id')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                DROP INDEX `idx_campaign_logs_campaign_status_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->indexExists('campaigns', 'idx_campaigns_org_status_id')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP INDEX `idx_campaigns_org_status_id`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
