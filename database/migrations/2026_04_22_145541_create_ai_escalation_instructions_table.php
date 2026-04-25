<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_escalation_instructions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->text('description');
            $table->enum('target_type', ['auto_assignment', 'specific_agent', 'bot_queue'])->default('auto_assignment');
            $table->unsignedBigInteger('target_agent_id')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'priority']);
            $table->index(['organization_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_escalation_instructions');
    }
};