<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_flows', function (Blueprint $table) {
            $table->boolean('reply_message_enabled')->default(false)->after('google_sheet_enabled');
            $table->text('reply_message_template')->nullable()->after('reply_message_enabled');

            $table->boolean('interactive_reply_enabled')->default(false)->after('reply_message_template');
            $table->json('interactive_reply_config')->nullable()->after('interactive_reply_enabled');

            $table->boolean('email_notification_enabled')->default(false)->after('interactive_reply_config');
            $table->json('email_notification_config')->nullable()->after('email_notification_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('meta_flows', function (Blueprint $table) {
            $table->dropColumn([
                'reply_message_enabled',
                'reply_message_template',
                'interactive_reply_enabled',
                'interactive_reply_config',
                'email_notification_enabled',
                'email_notification_config',
            ]);
        });
    }
};