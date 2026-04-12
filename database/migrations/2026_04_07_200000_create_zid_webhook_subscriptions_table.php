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
        Schema::create('zid_webhook_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->comment('Owner workspace');
            $table->string('platform')->default('zid');
            $table->string('store_id')->index();
            $table->string('event_name');
            $table->string('webhook_id')->index();
            $table->string('target_url');
            $table->json('conditions_json')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();

            $table->unique(['store_id', 'event_name'], 'zid_webhook_unique');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zid_webhook_subscriptions');
    }
};
