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
        Schema::create('chat_system_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')
        ->constrained('contacts')
        ->cascadeOnDelete();

        $table->string('type'); 
        $table->timestamp('sent_at');

        $table->index(['contact_id', 'type']);   
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_system_messages');
    }
};
