<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('templates', 'last_status_change_at')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->timestamp('last_status_change_at')
                    ->nullable()
                    ->after('status')
                    ->comment('Timestamp of the latest template status update received from Meta.');
            });
        }

        if (! Schema::hasColumn('templates', 'last_rejection_reason')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->text('last_rejection_reason')
                    ->nullable()
                    ->after('last_status_change_at')
                    ->comment('Most recent rejection reason supplied by Meta when the template is rejected.');
            });
        }

        if (! Schema::hasColumn('templates', 'quality_state')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->string('quality_state', 128)
                    ->nullable()
                    ->after('last_rejection_reason')
                    ->comment('Quality-related state derived from Meta template status webhooks, such as quality_issue or disabled.');
            });
        }

        if (! Schema::hasColumn('templates', 'disabled_at')) {
            Schema::table('templates', function (Blueprint $table) {
                $table->timestamp('disabled_at')
                    ->nullable()
                    ->after('quality_state')
                    ->comment('Timestamp when Meta disabled the template, if a DISABLED event is received.');
            });
        }
    }

    public function down(): void
    {
        foreach (['disabled_at', 'quality_state', 'last_rejection_reason', 'last_status_change_at'] as $column) {
            if (Schema::hasColumn('templates', $column)) {
                Schema::table('templates', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
