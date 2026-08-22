<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (! $this->indexExists('templates', 'idx_templates_campaign_dropdown')) {
                $table->index(
                    ['organization_id', 'deleted_at', 'status', 'created_at', 'id'],
                    'idx_templates_campaign_dropdown'
                );
            }
        });

        Schema::table('contact_groups', function (Blueprint $table) {
            if (! $this->indexExists('contact_groups', 'idx_contact_groups_campaign_dropdown')) {
                $table->index(
                    ['organization_id', 'deleted_at', 'created_at', 'id', 'contacts_count'],
                    'idx_contact_groups_campaign_dropdown'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if ($this->indexExists('templates', 'idx_templates_campaign_dropdown')) {
                $table->dropIndex('idx_templates_campaign_dropdown');
            }
        });

        Schema::table('contact_groups', function (Blueprint $table) {
            if ($this->indexExists('contact_groups', 'idx_contact_groups_campaign_dropdown')) {
                $table->dropIndex('idx_contact_groups_campaign_dropdown');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return count(DB::select(
            "SHOW INDEXES FROM `{$table}` WHERE Key_name = ?",
            [$index]
        )) > 0;
    }
};
