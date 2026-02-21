<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mark Meta Flows as plan-restricted so it appears in the subscription plan editor
        // under "Addons access", allowing admins to toggle it per plan.
        DB::table('addons')
            ->where('name', 'Meta Flows')
            ->update(['is_plan_restricted' => 1]);
    }

    public function down(): void
    {
        DB::table('addons')
            ->where('name', 'Meta Flows')
            ->update(['is_plan_restricted' => 0]);
    }
};
