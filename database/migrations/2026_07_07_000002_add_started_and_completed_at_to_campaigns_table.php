<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('campaigns', 'started_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD COLUMN `started_at` DATETIME NULL AFTER `scheduled_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (! $this->columnExists('campaigns', 'completed_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD COLUMN `completed_at` DATETIME NULL AFTER `started_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (! $this->indexExists('campaigns', 'idx_campaigns_started_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD INDEX `idx_campaigns_started_at` (`started_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if (! $this->indexExists('campaigns', 'idx_campaigns_completed_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                ADD INDEX `idx_campaigns_completed_at` (`completed_at`),
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('campaigns', 'idx_campaigns_completed_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP INDEX `idx_campaigns_completed_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->indexExists('campaigns', 'idx_campaigns_started_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP INDEX `idx_campaigns_started_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->columnExists('campaigns', 'completed_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP COLUMN `completed_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }

        if ($this->columnExists('campaigns', 'started_at')) {
            DB::statement('
                ALTER TABLE `campaigns`
                DROP COLUMN `started_at`,
                ALGORITHM=INPLACE,
                LOCK=NONE
            ');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        return count(DB::select(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?
             LIMIT 1',
            [$table, $column]
        )) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            'SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND INDEX_NAME = ?
             LIMIT 1',
            [$table, $index]
        )) > 0;
    }
};
