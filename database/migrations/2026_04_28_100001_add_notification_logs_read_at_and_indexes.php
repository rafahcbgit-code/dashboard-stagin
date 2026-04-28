<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('clicked');

            // Composite index for the unread count query: WHERE user_id = X AND organization_id = Y AND clicked = 0
            $table->index(['user_id', 'organization_id', 'clicked'], 'notif_user_org_clicked_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropIndex('notif_user_org_clicked_idx');
            $table->dropColumn('read_at');
        });
    }
};