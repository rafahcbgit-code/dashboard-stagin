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
        // Backfill old rows that don't have a source yet.
        DB::table('contacts')
            ->whereNull('contact_source')
            ->update(['contact_source' => 'whatsapp']);

        // Set DB-level default for upcoming inserts.
        DB::statement("ALTER TABLE contacts MODIFY contact_source VARCHAR(32) NULL DEFAULT 'platform'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE contacts MODIFY contact_source VARCHAR(32) NULL DEFAULT NULL");
    }
};

