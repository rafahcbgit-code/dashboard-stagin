<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename "Welcome" system trigger to "New Contact"
        DB::table('trigger_groups')
            ->where('type', 'new_contact')
            ->where('name', 'Welcome')
            ->where('is_system', true)
            ->update(['name' => 'New Contact']);

        // Standardize rate limits for system triggers
        DB::table('trigger_groups')
            ->where('type', 'greeting')
            ->where('is_system', true)
            ->update(['rate_limit_type' => '24h']);

        DB::table('trigger_groups')
            ->where('type', 'away')
            ->where('is_system', true)
            ->update(['rate_limit_type' => '24h']);

        DB::table('trigger_groups')
            ->where('type', 'new_contact')
            ->where('is_system', true)
            ->update(['rate_limit_type' => 'lifetime']);

        // Ensure fallback triggers always have the lowest priority (highest number)
        // so keyword/greeting/new_contact triggers are evaluated first
        DB::table('trigger_groups')
            ->where('type', 'fallback')
            ->where('is_system', true)
            ->update(['priority' => 999]);
    }

    public function down(): void
    {
        // Revert rename
        DB::table('trigger_groups')
            ->where('type', 'new_contact')
            ->where('name', 'New Contact')
            ->where('is_system', true)
            ->update(['name' => 'Welcome']);
    }
};