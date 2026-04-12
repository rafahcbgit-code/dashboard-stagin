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
        Schema::table('zid_webhook_subscriptions', function (Blueprint $table) {
            $table->string('original_id')->nullable()->after('event_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zid_webhook_subscriptions', function (Blueprint $table) {
            $table->dropColumn('original_id');
        });
    }
};
