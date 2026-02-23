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
        Schema::create('salla_webhook_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salla_webhook_event_setting_id');
            $table->string('template_variable', 191);
            $table->string('payload_path', 512);
            $table->string('transform', 64)->nullable();
            $table->string('default_value', 255)->nullable();
            $table->timestamps();

            $table->foreign('salla_webhook_event_setting_id', 'salla_webhook_event_mappings_setting_fk')
                ->references('id')
                ->on('salla_webhook_event_settings')
                ->onDelete('cascade');

            $table->unique(['salla_webhook_event_setting_id', 'template_variable'], 'salla_webhook_event_mappings_unique');
            $table->index(['salla_webhook_event_setting_id'], 'salla_webhook_event_mappings_setting_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salla_webhook_event_mappings');
    }
};

