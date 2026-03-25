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
        Schema::create('shopify_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('shop_domain')->nullable();
            $table->string('topic')->nullable();
            $table->string('event_id')->nullable();
            $table->string('payload_hash')->nullable()->index();
            $table->string('hmac')->nullable();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_deliveries');
    }
};
