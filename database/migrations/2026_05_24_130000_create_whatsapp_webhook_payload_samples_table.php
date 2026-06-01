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
        if (Schema::hasTable('whatsapp_webhook_payload_samples')) {
            return;
        }

        Schema::create('whatsapp_webhook_payload_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('webhook_type', 100)->nullable()->index();
            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedInteger('changes_count')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('status_count')->default(0);
            $table->unsignedInteger('echo_count')->default(0);
            $table->string('phone_number_id', 100)->nullable()->index();
            $table->string('sender_phone', 50)->nullable()->index();
            $table->json('sender_phone_numbers')->nullable();
            $table->json('wam_ids')->nullable();
            $table->char('payload_hash', 64)->unique();
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();

            $table->index(['organization_id', 'received_at'], 'idx_wa_payload_samples_org_received');
            $table->index(['webhook_type', 'received_at'], 'idx_wa_payload_samples_type_received');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_payload_samples');
    }
};
