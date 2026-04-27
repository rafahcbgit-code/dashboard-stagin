<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->json('variables')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->dropColumn('variables');
        });
    }
};