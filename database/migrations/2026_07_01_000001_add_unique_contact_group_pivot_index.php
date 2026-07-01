<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DELETE ccg1 FROM contact_contact_group ccg1
            INNER JOIN contact_contact_group ccg2
                ON ccg1.contact_id = ccg2.contact_id
                AND ccg1.contact_group_id = ccg2.contact_group_id
                AND ccg1.id > ccg2.id
        SQL);

        if (! $this->indexExists('contact_contact_group', 'idx_ccg_contact_group_unique')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->unique(['contact_id', 'contact_group_id'], 'idx_ccg_contact_group_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('contact_contact_group', 'idx_ccg_contact_group_unique')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->dropUnique('idx_ccg_contact_group_unique');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
