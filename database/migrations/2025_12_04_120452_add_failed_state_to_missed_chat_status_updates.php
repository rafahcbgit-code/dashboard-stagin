<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add `failed` state to existing ENUM
        DB::statement("
            ALTER TABLE missed_chat_status_updates 
            MODIFY state ENUM('not_processed', 'processing', 'processed', 'failed') 
            DEFAULT 'not_processed'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove `failed` from enum if rollback
        DB::statement("
            ALTER TABLE missed_chat_status_updates 
            MODIFY state ENUM('not_processed', 'processing', 'processed') 
            DEFAULT 'not_processed'
        ");
    }
};
