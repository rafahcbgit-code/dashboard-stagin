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
        Schema::table('salla_webhook_event_settings', function (Blueprint $table) {
            // Used by time-delayed automations (e.g. abandoned.cart).
            // The delay is defined in hours after the event "anchor time" in the payload (if available).
            $table->unsignedSmallInteger('send_delay_hours')->nullable()->after('recipient_phone_payload_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salla_webhook_event_settings', function (Blueprint $table) {
            $table->dropColumn('send_delay_hours');
        });
    }
};

