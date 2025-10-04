<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
        return !empty($indexes);
    }

    public function up(): void
    {
        // CRITICAL: Add wam_id composite index (most important!)
        if (!$this->indexExists('chats', 'idx_wam_org')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(['wam_id', 'organization_id'], 'idx_wam_org');
            });
            
        }

        // Add standalone wam_id index for queries that only use wam_id
        if (!$this->indexExists('chats', 'idx_wam_id')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index('wam_id', 'idx_wam_id');
            });
            
        }

        // Add organization_id index if not exists
        if (!$this->indexExists('chats', 'idx_organization_id')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index('organization_id', 'idx_organization_id');
            });
            
        }

        // Optimize contact queries
        if (!$this->indexExists('chats', 'idx_contact_org_created')) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(['contact_id', 'organization_id', 'deleted_at', 'created_at'], 'idx_contact_org_created');
            });
            
        }

        // CRITICAL: Add chat_id index to chat_status_logs
        if (!$this->indexExists('chat_status_logs', 'idx_chat_id')) {
            Schema::table('chat_status_logs', function (Blueprint $table) {
                $table->index('chat_id', 'idx_chat_id');
            });
            
        }

        // Optimize contacts queries
        if (!$this->indexExists('contacts', 'idx_org_latest_deleted')) {
            Schema::table('contacts', function (Blueprint $table) {
                $table->index(['organization_id', 'latest_chat_created_at', 'deleted_at'], 'idx_org_latest_deleted');
            });
            
        }

        // Optimize tables
        DB::statement('OPTIMIZE TABLE chats');
        DB::statement('OPTIMIZE TABLE contacts');
        DB::statement('OPTIMIZE TABLE chat_status_logs');
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            if ($this->indexExists('chats', 'idx_wam_org')) {
                $table->dropIndex('idx_wam_org');
            }
            if ($this->indexExists('chats', 'idx_wam_id')) {
                $table->dropIndex('idx_wam_id');
            }
            if ($this->indexExists('chats', 'idx_organization_id')) {
                $table->dropIndex('idx_organization_id');
            }
            if ($this->indexExists('chats', 'idx_contact_org_created')) {
                $table->dropIndex('idx_contact_org_created');
            }
        });

        Schema::table('contacts', function (Blueprint $table) {
            if ($this->indexExists('contacts', 'idx_org_latest_deleted')) {
                $table->dropIndex('idx_org_latest_deleted');
            }
        });

        Schema::table('chat_status_logs', function (Blueprint $table) {
            if ($this->indexExists('chat_status_logs', 'idx_chat_id')) {
                $table->dropIndex('idx_chat_id');
            }
        });
    }
};