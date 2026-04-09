<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zid_webhook_event_status_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zid_webhook_event_setting_id');
            $table->string('status_slug', 191);
            $table->string('status_name', 191)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->timestamps();

            $table->foreign('zid_webhook_event_setting_id', 'zid_wes_rules_fk')
                ->references('id')
                ->on('zid_webhook_event_settings')
                ->cascadeOnDelete();

            $table->foreign('template_id', 'zid_wes_rules_template_fk')
                ->references('id')
                ->on('templates')
                ->onDelete('set null');

            $table->unique(['zid_webhook_event_setting_id', 'status_slug'], 'zid_wes_rules_unique');
            $table->index(['zid_webhook_event_setting_id', 'enabled'], 'zid_wes_rules_setting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_webhook_event_status_rules');
    }
};
