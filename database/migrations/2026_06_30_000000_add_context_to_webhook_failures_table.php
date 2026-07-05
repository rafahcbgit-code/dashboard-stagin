<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('webhook_failures')) {
            return;
        }

        Schema::table('webhook_failures', function (Blueprint $table) {
            if (! Schema::hasColumn('webhook_failures', 'context')) {
                $table->longText('context')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webhook_failures')) {
            return;
        }

        Schema::table('webhook_failures', function (Blueprint $table) {
            if (Schema::hasColumn('webhook_failures', 'context')) {
                $table->dropColumn('context');
            }
        });
    }
};
