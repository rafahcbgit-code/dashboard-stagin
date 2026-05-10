<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enable the fallback system trigger for all organizations.
        // The fallback menu has been removed — the fallback trigger now serves
        // as the sole fallback mechanism, so it must be enabled by default.
        DB::table('trigger_groups')
            ->where('type', 'fallback')
            ->where('is_system', true)
            ->update(['is_enabled' => true]);
    }

    public function down(): void
    {
        // Revert: disable the fallback system trigger
        DB::table('trigger_groups')
            ->where('type', 'fallback')
            ->where('is_system', true)
            ->update(['is_enabled' => false]);
    }
};