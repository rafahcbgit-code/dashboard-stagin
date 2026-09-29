<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plan_notification_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->uuid('change_id')->unique();
            $table->json('old_metadata');
            $table->json('new_metadata');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['processed_at', 'queued_at', 'id'], 'subscription_plan_notification_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_notification_changes');
    }
};
