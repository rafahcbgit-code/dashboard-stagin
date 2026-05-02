<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTriggerGroupsTable extends Migration
{
    public function up()
    {
        Schema::create('trigger_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->enum('type', ['new_contact', 'new_message', 'greeting', 'away', 'fallback'])->default('new_message');
            $table->json('keywords')->nullable();
            $table->enum('match_mode', ['exact', 'ai'])->default('exact');
            $table->unsignedInteger('ai_confidence')->default(75);
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->enum('rate_limit_type', ['none', 'lifetime', '24h'])->default('none');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('flow_id')->references('id')->on('flows')->nullOnDelete();
            $table->index(['organization_id', 'type']);
            $table->index(['organization_id', 'priority']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('trigger_groups');
    }
}