<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_message_attempts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('outbound_message_id')->index();
            $table->unsignedInteger('attempt_no');

            $table->string('result', 10)->default('fail')->index(); // success|fail
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->foreign('outbound_message_id')
                ->references('id')
                ->on('outbound_messages')
                ->onDelete('cascade');

            $table->unique(['outbound_message_id', 'attempt_no'], 'outbound_message_attempts_unique_attempt');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_message_attempts');
    }
};

