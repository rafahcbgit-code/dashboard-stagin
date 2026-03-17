<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('processed_at');
            $table->index(['status', 'scheduled_for'], 'shopify_webhook_deliveries_status_scheduled_for_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('shopify_webhook_deliveries_status_scheduled_for_idx');
            $table->dropColumn('scheduled_for');
        });
    }
};
