<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalogue = require base_path('config/admin_permissions.php');

        DB::table('modules')->updateOrInsert(
            ['name' => 'organizations'],
            ['actions' => implode(', ', $catalogue['modules']['organizations'])]
        );
    }

    public function down(): void
    {
        // Existing permission records remain valid on rollback.
    }
};
