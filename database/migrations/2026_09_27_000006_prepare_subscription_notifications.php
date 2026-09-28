<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('renewal_mode', 16)->default('unknown');
            $table->timestamp('expiry_notification_processed_at')->nullable();
            $table->index(['status', 'valid_until', 'id'], 'subscriptions_notification_due');
        });

        Schema::create('subscription_usage_alert_states', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('resource', 64);
            $table->char('period_key', 64);
            $table->integer('last_limit');
            $table->unsignedTinyInteger('highest_threshold')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'resource'], 'subscription_usage_alert_identity');
        });

        Schema::table('subscription_events', function (Blueprint $table) {
            $table->timestamp('notification_projected_at')->nullable();
            $table->index(['notification_projected_at', 'id'], 'subscription_events_notification_pending');
        });

        DB::table('settings')->insertOrIgnore([
            'key' => 'subscription_notifications_started_at',
            'value' => now()->toDateTimeString(),
        ]);
        DB::table('settings')->insertOrIgnore([
            'key' => 'subscription_notification_source_start_id',
            'value' => (string) (DB::table('subscription_events')->max('id') ?? 0),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage_alert_states');
        Schema::table('subscription_events', function (Blueprint $table) {
            $table->dropIndex('subscription_events_notification_pending');
            $table->dropColumn('notification_projected_at');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_notification_due');
            $table->dropColumn('renewal_mode');
            $table->dropColumn('expiry_notification_processed_at');
        });
        DB::table('settings')->where('key', 'subscription_notifications_started_at')->delete();
        DB::table('settings')->where('key', 'subscription_notification_source_start_id')->delete();
    }
};
