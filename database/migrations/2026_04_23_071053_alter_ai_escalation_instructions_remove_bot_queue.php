<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update any existing bot_queue rows to auto_assignment before altering enum
        DB::table('ai_escalation_instructions')
            ->where('target_type', 'bot_queue')
            ->update(['target_type' => 'auto_assignment']);

        Schema::table('ai_escalation_instructions', function (Blueprint $table) {
            $table->enum('target_type', ['auto_assignment', 'specific_agent'])->default('auto_assignment')->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_escalation_instructions', function (Blueprint $table) {
            $table->enum('target_type', ['auto_assignment', 'specific_agent', 'bot_queue'])->default('auto_assignment')->change();
        });
    }
};