<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zid_webhook_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zid_webhook_event_setting_id')->index();
            $table->string('template_variable', 191);
            $table->string('payload_path', 512);
            $table->string('transform', 64)->nullable();
            $table->string('default_value', 255)->nullable();
            $table->timestamps();

            $table->foreign('zid_webhook_event_setting_id')
                ->references('id')
                ->on('zid_webhook_event_settings')
                ->cascadeOnDelete();

            $table->unique(['zid_webhook_event_setting_id', 'template_variable'], 'zid_webhook_event_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_webhook_event_mappings');
    }
};
