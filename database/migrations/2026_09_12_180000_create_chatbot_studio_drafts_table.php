<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_studio_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('admin_id')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('draft');
            $table->longText('source');
            $table->json('blueprint')->nullable();
            $table->json('conversation');
            $table->json('sources')->nullable();
            $table->json('errors')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_studio_drafts');
    }
};
