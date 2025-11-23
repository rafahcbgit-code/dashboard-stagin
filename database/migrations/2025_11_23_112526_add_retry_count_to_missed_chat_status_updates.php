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
        Schema::table('missed_chat_status_updates', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('state');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            
            // Add index for efficient querying
            $table->index(['state', 'next_retry_at', 'created_at'], 'idx_state_retry_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('missed_chat_status_updates', function (Blueprint $table) {
            // Drop the composite index first
            $table->dropIndex('idx_state_retry_created');
            
            // Then drop the columns
            $table->dropColumn(['retry_count', 'next_retry_at']);
        });
    }
};
