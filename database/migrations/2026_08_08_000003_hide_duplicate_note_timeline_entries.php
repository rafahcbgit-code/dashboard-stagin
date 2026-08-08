<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A note is mutable, so it has exactly one timeline entry: the one made
     * when it was created. Older code wrote a second row for every edit,
     * causing the same note to be rendered repeatedly. Keep the earliest
     * active entry and soft-delete only the later duplicates.
     */
    public function up(): void
    {
        if (! Schema::hasTable('chat_logs')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE chat_logs AS later
            INNER JOIN chat_logs AS first_entry
                ON first_entry.contact_id = later.contact_id
                AND first_entry.entity_type = 'notes'
                AND first_entry.entity_id = later.entity_id
                AND first_entry.deleted_at IS NULL
                AND first_entry.id < later.id
            SET later.deleted_at = CURRENT_TIMESTAMP
            WHERE later.entity_type = 'notes'
                AND later.deleted_at IS NULL
        SQL);
    }

    /**
     * This is deliberately irreversible: restoring duplicate timeline entries
     * would reintroduce the rendering bug.
     */
    public function down(): void
    {
        // No-op.
    }
};
