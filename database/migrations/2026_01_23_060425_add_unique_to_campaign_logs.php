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
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->unique(['campaign_id', 'contact_id'], 'unique_campaign_contact');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_logs', function (Blueprint $table) {
            $table->dropUnique('unique_campaign_contact');
        });
    }
};
