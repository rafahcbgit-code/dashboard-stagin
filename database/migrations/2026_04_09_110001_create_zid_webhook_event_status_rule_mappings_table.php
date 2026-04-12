<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zid_webhook_event_status_rule_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zid_webhook_event_status_rule_id');
            $table->string('template_variable', 191);
            $table->string('payload_path', 512);
            $table->string('transform', 64)->nullable();
            $table->string('default_value', 255)->nullable();
            $table->timestamps();

            $table->foreign('zid_webhook_event_status_rule_id', 'zid_wes_rule_map_fk')
                ->references('id')
                ->on('zid_webhook_event_status_rules')
                ->cascadeOnDelete();

            $table->unique(
                ['zid_webhook_event_status_rule_id', 'template_variable'],
                'zid_wes_rule_map_unique'
            );
            $table->index('zid_webhook_event_status_rule_id', 'zid_wes_rule_map_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_webhook_event_status_rule_mappings');
    }
};
