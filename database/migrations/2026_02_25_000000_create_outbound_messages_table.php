<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('organization_id')->index();

            // Source metadata (who/what triggered this message).
            $table->string('source', 50)->default('salla')->index();
            $table->unsignedBigInteger('source_delivery_id')->nullable()->index();
            $table->string('source_event', 191)->nullable()->index();
            $table->string('automation_key', 191)->nullable()->index();

            // Recipient + template snapshot.
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('recipient_e164', 32)->nullable()->index();
            $table->unsignedBigInteger('template_id')->nullable()->index();
            $table->string('template_name', 191)->nullable();
            $table->string('template_uuid', 64)->nullable();

            // Delivery channel/provider.
            $table->string('channel', 50)->default('whatsapp')->index();
            $table->string('provider', 50)->default('chat_service')->index();

            // Status + tracking.
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sent_chat_id')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error_message')->nullable();

            // Extra structured metadata (e.g., status_slug for order.status.updated).
            $table->json('context')->nullable();

            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');

            $table->foreign('source_delivery_id')
                ->references('id')
                ->on('salla_webhook_deliveries')
                ->onDelete('set null');

            $table->foreign('contact_id')
                ->references('id')
                ->on('contacts')
                ->onDelete('set null');

            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('set null');

            $table->foreign('sent_chat_id')
                ->references('id')
                ->on('chats')
                ->onDelete('set null');

            // Prevent duplicate message rows for the same automation key + delivery.
            $table->unique(['organization_id', 'source', 'source_delivery_id', 'automation_key'], 'outbound_messages_unique_delivery_automation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};

