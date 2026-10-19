<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'idx_contact_groups_org_name_search';

    public function up(): void
    {
        if (! $this->indexExists()) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->index(
                    ['organization_id', 'deleted_at', 'name', 'contacts_count'],
                    self::INDEX_NAME
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('contact_groups', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }

    private function indexExists(): bool
    {
        return count(DB::select(
            'SHOW INDEXES FROM `contact_groups` WHERE Key_name = ?',
            [self::INDEX_NAME]
        )) > 0;
    }
};
