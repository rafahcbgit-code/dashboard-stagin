<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zid_webhook_event_settings', function (Blueprint $table) {
            $table->unsignedInteger('send_delay_hours')->nullable()->after('recipient_phone_payload_path');
        });

        Schema::table('zid_webhook_deliveries', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('zid_webhook_event_settings', function (Blueprint $table) {
            $table->dropColumn('send_delay_hours');
        });

        Schema::table('zid_webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn('scheduled_for');
        });
    }
};
