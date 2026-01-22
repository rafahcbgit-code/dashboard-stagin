<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // First set pivot timestamps to the contact's creation time when available
        DB::statement("UPDATE contact_label cl INNER JOIN contacts c ON cl.contact_id = c.id SET cl.created_at = c.created_at, cl.updated_at = c.created_at WHERE cl.created_at IS NULL AND c.created_at IS NOT NULL");

        // Then set any remaining nulls to NOW()
        DB::statement("UPDATE contact_label SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL");
    }

    public function down(): void
    {
        // No-op: we don't revert timestamps
    }
};
