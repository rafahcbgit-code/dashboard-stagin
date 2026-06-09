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
            if (! Schema::hasColumn('webhook_failures', 'wam_id')) {
                $table->string('wam_id', 128)->nullable()->after('media_type')->index();
            }

            if (! Schema::hasColumn('webhook_failures', 'webhook_type')) {
                $table->string('webhook_type', 100)->nullable()->after('wam_id')->index();
            }

            if (! Schema::hasColumn('webhook_failures', 'queue')) {
                $table->string('queue', 100)->nullable()->after('webhook_type')->index();
            }

            if (! Schema::hasColumn('webhook_failures', 'sample_id')) {
                $table->unsignedBigInteger('sample_id')->nullable()->after('queue')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('webhook_failures')) {
            return;
        }

        Schema::table('webhook_failures', function (Blueprint $table) {
            foreach (['sample_id', 'queue', 'webhook_type', 'wam_id'] as $column) {
                if (Schema::hasColumn('webhook_failures', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
