<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropForeign(['source_delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->foreign('source_delivery_id')
                ->references('id')
                ->on('salla_webhook_deliveries')
                ->onDelete('set null');
        });
    }
};
