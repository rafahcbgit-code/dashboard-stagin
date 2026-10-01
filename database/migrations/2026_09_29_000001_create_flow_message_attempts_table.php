<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_message_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('flow_id')->nullable()->index();
            $table->unsignedBigInteger('chat_id')->nullable()->index();
            $table->uuid('flow_run_uuid')->nullable()->index();
            $table->string('node_id', 191)->nullable();
            $table->string('message_type', 100)->nullable();
            $table->string('result', 30)->index(); // sent|failed|delivery_unknown
            $table->string('failure_kind', 50)->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('provider_message_id', 191)->nullable();
            $table->string('meta_error_code', 100)->nullable();
            $table->string('meta_error_subcode', 100)->nullable();
            $table->string('meta_request_id', 191)->nullable()->index();
            $table->string('meta_trace_id', 191)->nullable();
            $table->string('exception_class', 255)->nullable();
            $table->integer('curl_errno')->nullable();
            $table->text('curl_error')->nullable();
            $table->string('json_error', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamps();

            $table->index(
                ['organization_id', 'contact_id', 'created_at'],
                'flow_message_attempts_contact_time'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_message_attempts');
    }
};
