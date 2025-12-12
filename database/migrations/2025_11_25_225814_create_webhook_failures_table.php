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
        if (Schema::hasTable('webhook_failures')) {
            return; // Table already exists
        }

        Schema::create('webhook_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('job_type', 100)->index(); // DownloadWebhookMediaJob, ProcessWebhookJob, etc.
            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('media_id')->nullable(); // WhatsApp media ID
            $table->string('media_type', 50)->nullable(); // image, video, document, audio, sticker
            $table->text('error_message');
            $table->string('error_code', 50)->nullable();
            $table->text('payload')->nullable(); // JSON payload for debugging
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'job_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_failures');
    }
};
