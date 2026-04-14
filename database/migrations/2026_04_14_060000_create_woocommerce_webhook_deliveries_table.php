<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('woocommerce_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('webhook_id')->nullable()->index();
            $table->string('topic', 191)->nullable()->index();
            $table->unsignedBigInteger('integration_id')->nullable()->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->char('payload_hash', 64)->index();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sent_chat_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('integration_id')
                ->references('id')
                ->on('woocommerce_integrations')
                ->onDelete('set null');

            $table->foreign('subscription_id')
                ->references('id')
                ->on('woocommerce_webhook_subscriptions')
                ->onDelete('set null');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');

            $table->foreign('sent_chat_id')
                ->references('id')
                ->on('chats')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('woocommerce_webhook_deliveries');
    }
};
