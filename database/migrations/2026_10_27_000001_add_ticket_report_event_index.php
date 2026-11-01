<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_ticket_logs', function (Blueprint $table) {
            $table->index(['contact_id', 'created_at', 'id'], 'idx_ticket_events_contact_time');
        });
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->index('chat_id', 'idx_campaign_logs_report_chat');
        });
    }

    public function down(): void
    {
        Schema::table('chat_ticket_logs', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_events_contact_time');
        });
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropIndex('idx_campaign_logs_report_chat');
        });
    }
};
