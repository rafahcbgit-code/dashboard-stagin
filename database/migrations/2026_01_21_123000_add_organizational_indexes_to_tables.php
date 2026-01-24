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
        Schema::table('chats', function (Blueprint $table) {
            // Speed up organizational filtering and dashboard counts
            if (!$this->indexExists('chats', 'idx_chats_dashboard_count')) {
                $table->index(['organization_id', 'deleted_at', 'contact_id'], 'idx_chats_dashboard_count');
            }

            // Speed up chat statistics (graphs)
            if (!$this->indexExists('chats', 'idx_chats_stats_covering')) {
                $table->index(['organization_id', 'type', 'deleted_at', 'created_at'], 'idx_chats_stats_covering');
            }

            // Speed up global unread count (shared via Inertia on every request)
            if (!$this->indexExists('chats', 'idx_chats_global_unread')) {
                $table->index(['organization_id', 'type', 'is_read', 'deleted_at'], 'idx_chats_global_unread');
            }

            // Speed up campaign delivery/read status lookups (subqueries)
            if (!$this->indexExists('chats', 'idx_chats_status_id')) {
                $table->index(['id', 'status'], 'idx_chats_status_id');
            }
        });

        Schema::table('contacts', function (Blueprint $table) {
            if (!$this->indexExists('contacts', 'idx_contacts_org_deleted')) {
                $table->index(['organization_id', 'deleted_at'], 'idx_contacts_org_deleted');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (!$this->indexExists('campaigns', 'idx_campaigns_org_deleted')) {
                $table->index(['organization_id', 'deleted_at'], 'idx_campaigns_org_deleted');
            }
        });

        Schema::table('templates', function (Blueprint $table) {
            if (!$this->indexExists('templates', 'idx_templates_org_deleted')) {
                $table->index(['organization_id', 'deleted_at'], 'idx_templates_org_deleted');
            }
        });

        Schema::table('campaign_logs', function (Blueprint $table) {
            if (!$this->indexExists('campaign_logs', 'idx_campaign_logs_lookup')) {
                $table->index(['campaign_id', 'status', 'chat_id'], 'idx_campaign_logs_lookup');
            }
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            if (!$this->indexExists('chat_logs', 'idx_chat_logs_contact_created')) {
                // Optimization for message history loading (order by created_at desc)
                $table->index(['contact_id', 'deleted_at', 'created_at'], 'idx_chat_logs_contact_created');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if ($this->indexExists('chats', 'idx_chats_dashboard_count')) {
                $table->dropIndex('idx_chats_dashboard_count');
            }
            if ($this->indexExists('chats', 'idx_chats_stats_covering')) {
                $table->dropIndex('idx_chats_stats_covering');
            }
            if ($this->indexExists('chats', 'idx_chats_global_unread')) {
                $table->dropIndex('idx_chats_global_unread');
            }
            if ($this->indexExists('chats', 'idx_chats_status_id')) {
                $table->dropIndex('idx_chats_status_id');
            }
        });

        Schema::table('contacts', function (Blueprint $table) {
            if ($this->indexExists('contacts', 'idx_contacts_org_deleted')) {
                $table->dropIndex('idx_contacts_org_deleted');
            }
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if ($this->indexExists('campaigns', 'idx_campaigns_org_deleted')) {
                $table->dropIndex('idx_campaigns_org_deleted');
            }
        });

        Schema::table('templates', function (Blueprint $table) {
            if ($this->indexExists('templates', 'idx_templates_org_deleted')) {
                $table->dropIndex('idx_templates_org_deleted');
            }
        });

        Schema::table('campaign_logs', function (Blueprint $table) {
            if ($this->indexExists('campaign_logs', 'idx_campaign_logs_lookup')) {
                $table->dropIndex('idx_campaign_logs_lookup');
            }
        });

        Schema::table('chat_logs', function (Blueprint $table) {
            if ($this->indexExists('chat_logs', 'idx_chat_logs_contact_created')) {
                $table->dropIndex('idx_chat_logs_contact_created');
            }
        });
    }

    /**
     * Check if an index exists on a given table.
     */
    protected function indexExists(string $table, string $index): bool
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($indexes) > 0;
    }
};
