<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        // Try INSTANT algorithm first (MySQL 8.0.12+) — no table copy, finishes in milliseconds
        try {
            DB::statement('ALTER TABLE contacts ADD COLUMN bot_auto_reenable_at TIMESTAMP NULL, ALGORITHM=INSTANT');
            Log::info('Migration: Added bot_auto_reenable_at using ALGORITHM=INSTANT');
            return;
        } catch (\Throwable $e) {
            // INSTANT not supported — fall through to INPLACE
        }

        // Try INPLACE algorithm (MySQL 5.6+) — no table copy, but still locks briefly
        try {
            DB::statement('ALTER TABLE contacts ADD COLUMN bot_auto_reenable_at TIMESTAMP NULL, ALGORITHM=INPLACE');
            Log::info('Migration: Added bot_auto_reenable_at using ALGORITHM=INPLACE');
            return;
        } catch (\Throwable $e) {
            // INPLACE not supported either — fall through to COPY
        }

        // Last resort: regular ALTER TABLE (copies entire table — slow on large tables)
        DB::statement('ALTER TABLE contacts ADD COLUMN bot_auto_reenable_at TIMESTAMP NULL');
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('bot_auto_reenable_at');
        });
    }
};