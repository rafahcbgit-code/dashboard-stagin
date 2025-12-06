<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 2.4: Add 'permanently_failed' status to campaign_logs.
 *
 * AC-2.4.3: After max retries, message marked permanently failed.
 * AC-2.4.2: Add index on retry_count for query optimization.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'permanently_failed' to the status ENUM
        DB::statement("ALTER TABLE campaign_logs MODIFY COLUMN status ENUM('pending', 'success', 'failed', 'ongoing', 'permanently_failed') NOT NULL");

        // Add index on retry_count for query optimization (AC-2.4.2)
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index('retry_count', 'campaign_logs_retry_count_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the index
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropIndex('campaign_logs_retry_count_index');
        });

        // Revert 'permanently_failed' to 'failed' before removing from ENUM
        DB::table('campaign_logs')
            ->where('status', 'permanently_failed')
            ->update(['status' => 'failed']);

        // Remove 'permanently_failed' from the status ENUM
        DB::statement("ALTER TABLE campaign_logs MODIFY COLUMN status ENUM('pending', 'success', 'failed', 'ongoing') NOT NULL");
    }
};
