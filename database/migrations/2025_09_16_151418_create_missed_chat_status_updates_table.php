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
        Schema::create('missed_chat_status_updates', function (Blueprint $table) {
            $table->id();
            $table->string('wam_id', 128)->index();
            $table->unsignedBigInteger('organization_id')->index();
            $table->text('payload');
            $table->string('status', 50); // delivered, read, failed, etc.
            $table->enum('state', ['not_processed', 'processing', 'processed'])->default('not_processed')->index();
            
            $table->timestamp('created_at');
            $table->timestamp('updated_at')->nullable();
            
            // Composite index for efficient querying
            $table->index(['state', 'created_at']);
            $table->index(['wam_id', 'organization_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('missed_chat_status_updates');
    }
};
