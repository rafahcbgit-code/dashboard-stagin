<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zid_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->unique();
            $table->string('store_id')->index();
            $table->unsignedBigInteger('integration_connection_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('event', 191)->nullable()->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->char('payload_hash', 64)->unique();
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sent_chat_id')->nullable()->index();
            $table->string('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->foreign('integration_connection_id')
                ->references('id')
                ->on('integration_connections')
                ->nullOnDelete();

            $table->foreign('sent_chat_id')
                ->references('id')
                ->on('chats')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zid_webhook_deliveries');
    }
};
