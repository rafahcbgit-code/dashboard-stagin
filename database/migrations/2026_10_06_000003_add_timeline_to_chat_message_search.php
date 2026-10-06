<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_message_search', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->nullable()->unique('chat_search_log_unique');
            $table->timestamp('log_created_at')->nullable();
            $table->string('message_type', 16)->nullable();
            $table->index(['organization_id', 'contact_id', 'log_created_at', 'log_id'], 'chat_search_timeline');
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_search', function (Blueprint $table) {
            $table->dropIndex('chat_search_timeline');
            $table->dropUnique('chat_search_log_unique');
            $table->dropColumn(['log_id', 'log_created_at', 'message_type']);
        });
    }
};
