<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_addons', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('resource', 64)->index();
            $table->unsignedInteger('quantity');
            $table->dateTime('valid_until')->nullable()->index();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('granted_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('subscription_usage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->string('resource', 64)->index();
            $table->integer('quantity')->default(1);
            $table->string('source_type', 64)->nullable()->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('idempotency_key', 191);
            $table->json('metadata')->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->unique(['organization_id', 'resource', 'idempotency_key'], 'usage_events_org_resource_idempotency_unique');
        });

        Schema::create('conversation_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->dateTime('opened_at')->index();
            $table->dateTime('closes_at')->index();
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('source_chat_id')->references('id')->on('chats')->nullOnDelete();
            $table->index(['organization_id', 'contact_id', 'opened_at'], 'conversation_windows_org_contact_opened_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_windows');
        Schema::dropIfExists('subscription_usage_events');
        Schema::dropIfExists('resource_addons');
    }
};
