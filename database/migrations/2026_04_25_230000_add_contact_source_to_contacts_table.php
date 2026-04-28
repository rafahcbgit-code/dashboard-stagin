<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contacts', 'contact_source')) {
            DB::statement('ALTER TABLE contacts ADD COLUMN contact_source VARCHAR(32) NULL DEFAULT NULL, ALGORITHM=INSTANT, LOCK=NONE');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        if (Schema::hasColumn('contacts', 'contact_source')) {
            DB::statement('ALTER TABLE contacts DROP COLUMN contact_source, ALGORITHM=INSTANT, LOCK=NONE');
        }
    }
};

