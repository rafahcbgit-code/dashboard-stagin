<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropColumn(['trigger', 'keywords']);
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->string('trigger')->nullable()->after('description');
            $table->text('keywords')->nullable()->after('trigger');
        });
    }
};