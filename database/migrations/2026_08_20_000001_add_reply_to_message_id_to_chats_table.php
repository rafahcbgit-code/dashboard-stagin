<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chats', 'reply_to_message_id')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('wam_id');
                $table->index('reply_to_message_id', 'idx_chats_reply_to_message_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chats', 'reply_to_message_id')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex('idx_chats_reply_to_message_id');
                $table->dropColumn('reply_to_message_id');
            });
        }
    }
};
