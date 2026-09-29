<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            // Keep the tenant identifier for audit without blocking existing organization deletion flows.
            $table->unsignedBigInteger('organization_id');
            $table->string('type_key', 100);
            $table->char('event_key', 64);
            $table->json('variables');
            $table->boolean('is_test')->default(false);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('definition_revision')->nullable();
            $table->json('rendered')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'type_key', 'event_key'], 'notification_event_identity');
            $table->index(['status', 'next_attempt_at', 'id'], 'notification_events_due');
            $table->index(['status', 'created_at', 'id'], 'notification_events_retention');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
