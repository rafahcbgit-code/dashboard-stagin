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
        Schema::table('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->string('dedupe_key', 191)->nullable()->after('topic');
            $table->timestamp('scheduled_for')->nullable()->after('processed_at');

            $table->index(['organization_id', 'topic', 'dedupe_key'], 'woo_webhook_delivery_dedupe_idx');
            $table->index(['status', 'scheduled_for'], 'woo_webhook_delivery_schedule_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('woo_webhook_delivery_dedupe_idx');
            $table->dropIndex('woo_webhook_delivery_schedule_idx');
            $table->dropColumn('dedupe_key');
            $table->dropColumn('scheduled_for');
        });
    }
};
