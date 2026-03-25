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
        Schema::create('shopify_webhook_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_webhook_event_setting_id');
            $table->string('template_variable', 191);
            $table->string('payload_path', 512);
            $table->string('transform', 64)->nullable();
            $table->string('default_value', 255)->nullable();
            $table->timestamps();

            $table->foreign('shopify_webhook_event_setting_id', 'shopify_webhook_event_mappings_setting_fk')
                ->references('id')
                ->on('shopify_webhook_event_settings')
                ->onDelete('cascade');

            $table->unique(
                ['shopify_webhook_event_setting_id', 'template_variable'],
                'shopify_webhook_event_mappings_unique'
            );
            $table->index(['shopify_webhook_event_setting_id'], 'shopify_webhook_event_mappings_setting_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_event_mappings');
    }
};
