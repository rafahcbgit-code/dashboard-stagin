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
        if (! Schema::hasTable('whatsapp_webhook_payload_samples')) {
            return;
        }

        Schema::table('whatsapp_webhook_payload_samples', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_webhook_payload_samples', 'sender_phone')) {
                $table->string('sender_phone', 50)->nullable()->after('phone_number_id')->index();
            }

            if (! Schema::hasColumn('whatsapp_webhook_payload_samples', 'sender_phone_numbers')) {
                $table->json('sender_phone_numbers')->nullable()->after('sender_phone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('whatsapp_webhook_payload_samples')) {
            return;
        }

        Schema::table('whatsapp_webhook_payload_samples', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_webhook_payload_samples', 'sender_phone_numbers')) {
                $table->dropColumn('sender_phone_numbers');
            }

            if (Schema::hasColumn('whatsapp_webhook_payload_samples', 'sender_phone')) {
                $table->dropColumn('sender_phone');
            }
        });
    }
};
