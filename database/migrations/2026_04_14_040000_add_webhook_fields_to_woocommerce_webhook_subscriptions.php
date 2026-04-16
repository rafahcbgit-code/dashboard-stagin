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
        Schema::table('woocommerce_webhook_subscriptions', function (Blueprint $table) {
            $table->string('webhook_id')->nullable()->after('response_payload');
            $table->boolean('is_active')->default(true)->after('webhook_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('woocommerce_webhook_subscriptions', function (Blueprint $table) {
            $table->dropColumn('webhook_id');
            $table->dropColumn('is_active');
        });
    }
};
