<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_created_status_chat')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                ADD INDEX `idx_campaign_logs_campaign_created_status_chat`
                    (`campaign_id`, `created_at`, `status`, `chat_id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (!$this->indexExists('campaigns', 'idx_campaigns_org_deleted_id')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD INDEX `idx_campaigns_org_deleted_id`
                    (`organization_id`, `deleted_at`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_created_status_chat')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                DROP INDEX `idx_campaign_logs_campaign_created_status_chat`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->indexExists('campaigns', 'idx_campaigns_org_deleted_id')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP INDEX `idx_campaigns_org_deleted_id`,
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
