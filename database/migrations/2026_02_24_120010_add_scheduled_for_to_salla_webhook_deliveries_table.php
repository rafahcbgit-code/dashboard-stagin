<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('salla_webhook_deliveries', function (Blueprint $table) {
            // When a delivery is scheduled for delayed processing (e.g. abandoned.cart),
            // we keep the delivery record and re-run the job at this timestamp.
            $table->timestamp('scheduled_for')->nullable()->after('processed_at');
            $table->index(['status', 'scheduled_for'], 'salla_webhook_deliveries_status_scheduled_for_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salla_webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('salla_webhook_deliveries_status_scheduled_for_idx');
            $table->dropColumn('scheduled_for');
        });
    }
};

