<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_ticket_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('trigger_chat_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('chat_ticket_logs', function (Blueprint $table) {
            $table->dropIndex(['trigger_chat_id']);
            $table->dropColumn('trigger_chat_id');
        });
    }
};
