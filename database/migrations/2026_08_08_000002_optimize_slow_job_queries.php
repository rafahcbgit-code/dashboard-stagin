<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing(
            'jobs',
            'idx_jobs_queue_reserved_available',
            '(queue, reserved_at, available_at)'
        );

        // Used by the assigned-agent note notification: filter by contact and
        // organization, then read the newest ticket without a filesort.
        $this->addIndexIfMissing(
            'chat_tickets',
            'idx_chat_tickets_contact_org_id_assigned',
            '(contact_id, organization_id, id, assigned_to)'
        );
    }

    public function down(): void
    {
        $this->dropIndexIfExists('chat_tickets', 'idx_chat_tickets_contact_org_id_assigned');
        $this->dropIndexIfExists('jobs', 'idx_jobs_queue_reserved_available');
    }

    private function addIndexIfMissing(string $table, string $index, string $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        DB::statement(<<<SQL
            ALTER TABLE {$table}
            ADD INDEX {$index} {$columns},
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        DB::statement(<<<SQL
            ALTER TABLE {$table}
            DROP INDEX {$index},
            ALGORITHM=INPLACE,
            LOCK=NONE
        SQL);
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
