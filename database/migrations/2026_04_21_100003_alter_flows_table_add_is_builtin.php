<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterFlowsTableAddIsBuiltin extends Migration
{
    public function up()
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->boolean('is_builtin')->default(false)->after('status');
        });
    }

    public function down()
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn('is_builtin');
        });
    }
}