<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_search', function (Blueprint $table) {
            $table->unsignedBigInteger('chat_id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id');
            $table->text('display_text');
            $table->text('search_text');
            $table->timestamp('created_at');
            $table->fullText('search_text', 'chat_message_search_fulltext');
            $table->index(['organization_id', 'contact_id', 'created_at', 'chat_id'], 'chat_message_search_conversation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_search');
    }
};
