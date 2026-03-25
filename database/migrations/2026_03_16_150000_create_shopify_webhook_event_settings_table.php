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
        Schema::create('shopify_webhook_event_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('event', 191);
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('template_id')->nullable();
            $table->string('recipient_phone_payload_path', 512)->nullable();
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');

            $table->foreign('template_id')
                ->references('id')
                ->on('templates')
                ->onDelete('set null');

            $table->unique(['organization_id', 'event']);
            $table->index(['organization_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shopify_webhook_event_settings');
    }
};
