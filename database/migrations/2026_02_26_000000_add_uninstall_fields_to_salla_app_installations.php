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
        Schema::table('salla_app_installations', function (Blueprint $table) {
            $table->timestamp('uninstallation_date')->nullable()->after('installation_date');
            $table->boolean('uninstalled')->default(false)->after('uninstallation_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salla_app_installations', function (Blueprint $table) {
            $table->dropColumn(['uninstallation_date', 'uninstalled']);
        });
    }
};
