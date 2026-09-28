<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('period_version')->default(1);
            $table->unsignedBigInteger('usage_event_start_id')->default(0);
            $table->unsignedBigInteger('conversation_start_id')->default(0);
        });
        Schema::create('subscription_period_archives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedInteger('period_version');
            $table->uuid('operation_key');
            $table->json('plan_snapshot');
            $table->json('usage_snapshot');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('scheduled_end_at')->nullable();
            $table->dateTime('closed_at');
            $table->unsignedBigInteger('usage_event_start_id')->default(0);
            $table->unsignedBigInteger('usage_event_end_id')->default(0);
            $table->unsignedBigInteger('conversation_start_id')->default(0);
            $table->unsignedBigInteger('conversation_end_id')->default(0);
            $table->dateTime('renewed_until');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['organization_id', 'operation_key'], 'subscription_archives_operation_unique');
            $table->unique(['subscription_id', 'period_version'], 'subscription_archives_period_unique');
            $table->index(['organization_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_period_archives');
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['period_version', 'usage_event_start_id', 'conversation_start_id']);
        });
    }
};
