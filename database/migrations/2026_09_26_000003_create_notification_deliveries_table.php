<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id');
            $table->string('channel', 20);
            $table->char('recipient_key', 64);
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->text('destination')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('notification_log_id')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'channel', 'recipient_key'], 'notification_delivery_identity');
            $table->index(['status', 'next_attempt_at', 'id'], 'notification_deliveries_due');
            $table->index(['organization_id', 'status', 'created_at'], 'notification_deliveries_org_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
