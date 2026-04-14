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
        Schema::create('woocommerce_webhook_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('woocommerce_webhook_event_setting_id');
            $table->string('template_variable', 191);
            $table->string('payload_path', 512);
            $table->string('transform', 64)->nullable();
            $table->string('default_value', 255)->nullable();
            $table->timestamps();

            $table->foreign('woocommerce_webhook_event_setting_id', 'woocommerce_webhook_event_mappings_setting_fk')
                ->references('id')
                ->on('woocommerce_webhook_event_settings')
                ->onDelete('cascade');

            $table->unique(
                ['woocommerce_webhook_event_setting_id', 'template_variable'],
                'woocommerce_webhook_event_mappings_unique'
            );
            $table->index(['woocommerce_webhook_event_setting_id'], 'woocommerce_webhook_event_mappings_setting_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woocommerce_webhook_event_mappings');
    }
};
