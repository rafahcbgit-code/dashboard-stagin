<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $indexName = 'contact_contact_group_unique_pair';

    public function up(): void
    {
        // Cleanup duplicates first to allow creating a unique index safely.
        DB::statement('
            DELETE ccg1
            FROM contact_contact_group ccg1
            INNER JOIN contact_contact_group ccg2
                ON ccg1.contact_id = ccg2.contact_id
                AND ccg1.contact_group_id = ccg2.contact_group_id
                AND ccg1.id > ccg2.id
        ');

        if (! $this->indexExists('contact_contact_group', $this->indexName)) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->unique(['contact_id', 'contact_group_id'], 'contact_contact_group_unique_pair');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('contact_contact_group', $this->indexName)) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->dropUnique('contact_contact_group_unique_pair');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$indexName]);
        return ! empty($result);
    }
};

