<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add a temporary index to speed up the cleanup process
        Schema::table('contacts', function (Blueprint $table) {
            $table->index(['organization_id', 'phone'], 'temp_idx_org_phone');
        });

        // Cleanup duplicates before adding unique index
        // Keep the one with the smallest ID (oldest) and delete others
        // This will now use the temporary index and be much faster
        DB::statement("
            DELETE c1 FROM contacts c1
            INNER JOIN contacts c2 
            WHERE 
                c1.id > c2.id AND 
                c1.organization_id = c2.organization_id AND 
                c1.phone = c2.phone
        ");

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('temp_idx_org_phone');
            $table->unique(['organization_id', 'phone'], 'idx_contacts_org_phone_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique('idx_contacts_org_phone_unique');
        });
    }
};
