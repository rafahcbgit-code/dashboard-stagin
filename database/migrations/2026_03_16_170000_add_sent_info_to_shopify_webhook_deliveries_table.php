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
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('sent_chat_id')->nullable()->after('attempts');
            $table->text('error')->nullable()->after('sent_chat_id');

            $table->index('sent_chat_id', 'shopify_webhook_deliveries_sent_chat_idx');
            $table->foreign('sent_chat_id')
                ->references('id')
                ->on('chats')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->dropForeign(['sent_chat_id']);
            $table->dropIndex('shopify_webhook_deliveries_sent_chat_idx');
            $table->dropColumn(['sent_chat_id', 'error']);
        });
    }
};
