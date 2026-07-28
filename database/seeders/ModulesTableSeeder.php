<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModulesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $modules = config('admin_permissions.modules', []);

        foreach ($modules as $name => $actions) {
            DB::table('modules')->updateOrInsert(
                ['name' => $name],
                ['actions' => implode(', ', $actions)]
            );
        }
    }
}
