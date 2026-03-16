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
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('topic');
            $table->unsignedBigInteger('shopify_store_id')->nullable()->after('organization_id');
            $table->index(['organization_id', 'topic'], 'shopify_webhook_deliveries_org_topic_idx');
            $table->index('shopify_store_id', 'shopify_webhook_deliveries_store_idx');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('set null');

            $table->foreign('shopify_store_id')
                ->references('id')
                ->on('shopify_stores')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['shopify_store_id']);
            $table->dropIndex('shopify_webhook_deliveries_org_topic_idx');
            $table->dropIndex('shopify_webhook_deliveries_store_idx');
            $table->dropColumn(['organization_id', 'shopify_store_id']);
        });
    }
};
