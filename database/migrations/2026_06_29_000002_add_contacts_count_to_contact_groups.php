<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('contact_groups', 'contacts_count')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->unsignedInteger('contacts_count')->default(0)->after('created_by');
                $table->index(['organization_id', 'deleted_at', 'contacts_count'], 'idx_contact_groups_org_count');
            });
        }

        if (
            Schema::hasColumn('contact_groups', 'contacts_count')
            && !$this->indexExists('contact_groups', 'idx_contact_groups_org_count')
        ) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->index(['organization_id', 'deleted_at', 'contacts_count'], 'idx_contact_groups_org_count');
            });
        }

        if (!$this->indexExists('contact_contact_group', 'idx_ccg_group_contact')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->index(['contact_group_id', 'contact_id'], 'idx_ccg_group_contact');
            });
        }

        // Counts are maintained from Laravel code to avoid requiring MySQL TRIGGER/SUPER privileges.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('contact_groups', 'contacts_count')) {
            Schema::table('contact_groups', function (Blueprint $table) {
                if ($this->indexExists('contact_groups', 'idx_contact_groups_org_count')) {
                    $table->dropIndex('idx_contact_groups_org_count');
                }
                $table->dropColumn('contacts_count');
            });
        }

        if ($this->indexExists('contact_contact_group', 'idx_ccg_group_contact')) {
            Schema::table('contact_contact_group', function (Blueprint $table) {
                $table->dropIndex('idx_ccg_group_contact');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);

        return count($indexes) > 0;
    }
};
