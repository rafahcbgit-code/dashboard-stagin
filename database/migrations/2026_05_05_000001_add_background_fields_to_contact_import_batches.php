<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        return ! empty(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]));
    }

    public function up(): void
    {
        Schema::table('contact_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_import_batches', 'mode')) {
                $table->string('mode', 16)->default('sync')->after('status');
            }

            if (! Schema::hasColumn('contact_import_batches', 'source_file_path')) {
                $table->string('source_file_path')->nullable()->after('mode');
            }

            if (! Schema::hasColumn('contact_import_batches', 'original_file_name')) {
                $table->string('original_file_name')->nullable()->after('source_file_path');
            }

            if (! Schema::hasColumn('contact_import_batches', 'total_rows')) {
                $table->unsignedInteger('total_rows')->default(0)->after('original_file_name');
            }

            if (! Schema::hasColumn('contact_import_batches', 'processed_rows')) {
                $table->unsignedInteger('processed_rows')->default(0)->after('total_rows');
            }

            if (! Schema::hasColumn('contact_import_batches', 'valid_rows')) {
                $table->unsignedInteger('valid_rows')->default(0)->after('processed_rows');
            }

            if (! Schema::hasColumn('contact_import_batches', 'invalid_rows')) {
                $table->unsignedInteger('invalid_rows')->default(0)->after('valid_rows');
            }

            if (! Schema::hasColumn('contact_import_batches', 'created_count')) {
                $table->unsignedInteger('created_count')->default(0)->after('invalid_rows');
            }

            if (! Schema::hasColumn('contact_import_batches', 'updated_count')) {
                $table->unsignedInteger('updated_count')->default(0)->after('created_count');
            }

            if (! Schema::hasColumn('contact_import_batches', 'failed_count')) {
                $table->unsignedInteger('failed_count')->default(0)->after('updated_count');
            }

            if (! Schema::hasColumn('contact_import_batches', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('failed_count');
            }

            if (! Schema::hasColumn('contact_import_batches', 'finished_at')) {
                $table->timestamp('finished_at')->nullable()->after('started_at');
            }

            if (! Schema::hasColumn('contact_import_batches', 'error_message')) {
                $table->text('error_message')->nullable()->after('finished_at');
            }
        });

        Schema::table('contact_import_rows', function (Blueprint $table) {
            if (! $this->indexExists('contact_import_rows', 'contact_import_rows_batch_row_number_idx')) {
                $table->index(['contact_import_batch_id', 'row_number'], 'contact_import_rows_batch_row_number_idx');
            }

            if (! $this->indexExists('contact_import_rows', 'contact_import_rows_batch_status_id_idx')) {
                $table->index(['contact_import_batch_id', 'status', 'id'], 'contact_import_rows_batch_status_id_idx');
            }
        });

        DB::statement('
            DELETE ccg1 FROM contact_contact_group ccg1
            INNER JOIN contact_contact_group ccg2
                ON ccg1.id > ccg2.id
                AND ccg1.contact_id = ccg2.contact_id
                AND ccg1.contact_group_id = ccg2.contact_group_id
        ');

        Schema::table('contact_contact_group', function (Blueprint $table) {
            if (! $this->indexExists('contact_contact_group', 'contact_contact_group_contact_group_unique')) {
                $table->unique(['contact_id', 'contact_group_id'], 'contact_contact_group_contact_group_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contact_contact_group', function (Blueprint $table) {
            if ($this->indexExists('contact_contact_group', 'contact_contact_group_contact_group_unique')) {
                $table->dropUnique('contact_contact_group_contact_group_unique');
            }
        });

        Schema::table('contact_import_rows', function (Blueprint $table) {
            if ($this->indexExists('contact_import_rows', 'contact_import_rows_batch_row_number_idx')) {
                $table->dropIndex('contact_import_rows_batch_row_number_idx');
            }

            if ($this->indexExists('contact_import_rows', 'contact_import_rows_batch_status_id_idx')) {
                $table->dropIndex('contact_import_rows_batch_status_id_idx');
            }
        });

        Schema::table('contact_import_batches', function (Blueprint $table) {
            foreach ([
                'error_message',
                'finished_at',
                'started_at',
                'failed_count',
                'updated_count',
                'created_count',
                'invalid_rows',
                'valid_rows',
                'processed_rows',
                'total_rows',
                'original_file_name',
                'source_file_path',
                'mode',
            ] as $column) {
                if (Schema::hasColumn('contact_import_batches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
