<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_id_id')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                ADD INDEX `idx_campaign_logs_campaign_id_id` (`campaign_id`, `id`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('campaign_logs', 'idx_campaign_logs_campaign_id_id')) {
            DB::statement('
                ALTER TABLE `campaign_logs`
                DROP INDEX `idx_campaign_logs_campaign_id_id`,
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
