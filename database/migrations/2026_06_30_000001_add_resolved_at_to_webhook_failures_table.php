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
            if (! Schema::hasColumn('webhook_failures', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('attempts')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webhook_failures')) {
            return;
        }

        Schema::table('webhook_failures', function (Blueprint $table) {
            if (Schema::hasColumn('webhook_failures', 'resolved_at')) {
                $table->dropColumn('resolved_at');
            }
        });
    }
};
