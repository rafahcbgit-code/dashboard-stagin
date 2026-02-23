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
        Schema::create('salla_webhook_event_status_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('salla_webhook_event_setting_id');
            $table->string('status_slug', 191);
            $table->string('status_name', 191)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();

            $table->foreign('salla_webhook_event_setting_id', 'salla_webhook_event_status_rules_setting_fk')
                ->references('id')
                ->on('salla_webhook_event_settings')
                ->onDelete('cascade');

            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('set null');

            $table->unique(['salla_webhook_event_setting_id', 'status_slug'], 'salla_webhook_event_status_rules_unique');
            $table->index(['salla_webhook_event_setting_id', 'enabled'], 'salla_webhook_event_status_rules_setting_enabled_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salla_webhook_event_status_rules');
    }
};

