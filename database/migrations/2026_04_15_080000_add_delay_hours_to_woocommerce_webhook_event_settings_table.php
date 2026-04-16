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
        Schema::table('woocommerce_webhook_event_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('delay_hours')->default(0)->after('recipient_phone_payload_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woocommerce_webhook_event_settings', function (Blueprint $table) {
            $table->dropColumn('delay_hours');
        });
    }
};
