<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_flow_responses', function (Blueprint $table) {
            $table->timestamp('processed_at')->nullable()->after('response_json');
            $table->timestamp('reply_sent_at')->nullable()->after('processed_at');
            $table->timestamp('interactive_reply_sent_at')->nullable()->after('reply_sent_at');
            $table->timestamp('email_notification_sent_at')->nullable()->after('interactive_reply_sent_at');

            $table->index(['organization_id', 'flow_token'], 'meta_flow_responses_org_token_index');
        });
    }

    public function down(): void
    {
        Schema::table('meta_flow_responses', function (Blueprint $table) {
            $table->dropIndex('meta_flow_responses_org_token_index');
            $table->dropColumn([
                'processed_at',
                'reply_sent_at',
                'interactive_reply_sent_at',
                'email_notification_sent_at',
            ]);
        });
    }
};
