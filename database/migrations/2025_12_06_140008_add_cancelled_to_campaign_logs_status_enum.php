<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Story 2.6: AC-2.6.2, AC-2.6.3 - Add 'cancelled' to campaign_logs status ENUM
 *
 * CRITICAL: This migration modifies an ENUM column which may briefly lock the table.
 * Schedule during low-traffic periods for production deployments.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE campaign_logs MODIFY COLUMN status ENUM('pending', 'success', 'failed', 'ongoing', 'permanently_failed', 'cancelled') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First update any cancelled logs to pending to avoid data loss
        DB::statement("UPDATE campaign_logs SET status = 'pending' WHERE status = 'cancelled'");

        // Then remove cancelled from ENUM
        DB::statement("ALTER TABLE campaign_logs MODIFY COLUMN status ENUM('pending', 'success', 'failed', 'ongoing', 'permanently_failed') NOT NULL");
    }
};
