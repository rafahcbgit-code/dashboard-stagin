<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->unsignedBigInteger('resource_addon_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('source', 64)->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_guard', 32)->nullable();
            $table->string('description')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('subscription_plans')->nullOnDelete();
            $table->foreign('resource_addon_id')->references('id')->on('resource_addons')->nullOnDelete();
            $table->unique(['organization_id', 'event_type', 'idempotency_key'], 'subscription_events_unique_event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
    }
};
