<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->timestamp('rejoin_sent_at')->nullable()->after('variables');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flow_user_data', function (Blueprint $table) {
            $table->dropColumn('rejoin_sent_at');
        });
    }
};
