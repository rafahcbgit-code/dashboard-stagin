<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salla_app_installations', function (Blueprint $table) {
            $table->id();

            // Salla identifiers.
            $table->string('merchant_id')->index();
            $table->unsignedBigInteger('salla_store_id')->nullable()->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('source_delivery_id')->nullable()->index();

            $table->unsignedBigInteger('salla_installation_id')->nullable()->index();

            // App metadata snapshot.
            $table->string('app_name', 191)->nullable();
            $table->text('app_description')->nullable();
            $table->string('app_type', 50)->nullable();
            $table->string('store_type', 50)->nullable();
            $table->json('app_scopes')->nullable();
            $table->unsignedInteger('scopes_count')->default(0);

            // Timestamps from payload + observability.
            $table->timestamp('installation_date')->nullable();
            $table->timestamp('webhook_created_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();

            // Keep a copy of the last payload for debugging/support.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->foreign('salla_store_id')
                ->references('id')
                ->on('salla_stores')
                ->onDelete('set null');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('set null');

            $table->foreign('source_delivery_id')
                ->references('id')
                ->on('salla_webhook_deliveries')
                ->onDelete('set null');

            // One install record per (merchant, installation id). Also protects from webhook retries.
            $table->unique(['merchant_id', 'salla_installation_id'], 'salla_app_installations_unique_merchant_install');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salla_app_installations');
    }
};

