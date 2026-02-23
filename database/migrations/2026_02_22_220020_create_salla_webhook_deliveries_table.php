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
        Schema::create('salla_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 50)->unique();
            $table->string('merchant_id')->nullable()->index();
            $table->unsignedBigInteger('salla_store_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('event', 191)->nullable()->index();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->char('payload_hash', 64)->unique();
            $table->string('status', 50)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('sent_chat_id')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('salla_store_id')
                ->references('id')
                ->on('salla_stores')
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
        Schema::dropIfExists('salla_webhook_deliveries');
    }
};

