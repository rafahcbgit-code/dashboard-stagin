<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $catalogue = require base_path('config/admin_permissions.php');

        foreach ($catalogue['modules'] as $name => $actions) {
            DB::table('modules')->updateOrInsert(
                ['name' => $name],
                ['actions' => implode(', ', $actions)]
            );
        }
    }

    public function down(): void
    {
        // Preserve existing module rows on rollback.
    }
};
