<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        DB::statement('ALTER TABLE subscriptions MODIFY COLUMN status ENUM(\'trial\',\'active\',\'suspended\') NOT NULL DEFAULT \'trial\';');
    }

    public function down()
    {
        DB::statement('ALTER TABLE subscriptions MODIFY COLUMN status ENUM(\'trial\',\'active\') NOT NULL DEFAULT \'trial\';');
    }
};
