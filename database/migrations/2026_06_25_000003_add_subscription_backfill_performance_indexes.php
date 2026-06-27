<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            if (! $this->indexExists('chats', 'idx_chats_org_contact_created_id')) {
                $table->index(['organization_id', 'contact_id', 'created_at', 'id'], 'idx_chats_org_contact_created_id');
            }

            if (! $this->indexExists('chats', 'idx_chats_type_status_id')) {
                $table->index(['type', 'status', 'id'], 'idx_chats_type_status_id');
            }

            if (! $this->indexExists('chats', 'idx_chats_org_type_status_id')) {
                $table->index(['organization_id', 'type', 'status', 'id'], 'idx_chats_org_type_status_id');
            }
        });

        if (Schema::hasTable('campaign_logs')) {
            Schema::table('campaign_logs', function (Blueprint $table): void {
                if (! $this->indexExists('campaign_logs', 'idx_campaign_logs_chat_status_campaign')) {
                    $table->index(['chat_id', 'status', 'campaign_id'], 'idx_campaign_logs_chat_status_campaign');
                }
            });
        }

        if (Schema::hasTable('outbound_messages') && Schema::hasColumn('outbound_messages', 'sent_chat_id')) {
            Schema::table('outbound_messages', function (Blueprint $table): void {
                if (! $this->indexExists('outbound_messages', 'idx_outbound_messages_sent_chat_template')) {
                    $table->index(['sent_chat_id', 'template_id'], 'idx_outbound_messages_sent_chat_template');
                }
            });
        }

        foreach ([
            'salla_webhook_deliveries',
            'zid_webhook_deliveries',
            'shopify_webhook_deliveries',
            'woocommerce_webhook_deliveries',
        ] as $deliveryTable) {
            if (! Schema::hasTable($deliveryTable) || ! Schema::hasColumn($deliveryTable, 'sent_chat_id')) {
                continue;
            }

            Schema::table($deliveryTable, function (Blueprint $table) use ($deliveryTable): void {
                $indexName = "idx_{$deliveryTable}_sent_chat";

                if (! $this->indexExists($deliveryTable, $indexName)) {
                    $table->index('sent_chat_id', $indexName);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table): void {
            if ($this->indexExists('chats', 'idx_chats_org_contact_created_id')) {
                $table->dropIndex('idx_chats_org_contact_created_id');
            }

            if ($this->indexExists('chats', 'idx_chats_type_status_id')) {
                $table->dropIndex('idx_chats_type_status_id');
            }

            if ($this->indexExists('chats', 'idx_chats_org_type_status_id')) {
                $table->dropIndex('idx_chats_org_type_status_id');
            }
        });

        if (Schema::hasTable('campaign_logs')) {
            Schema::table('campaign_logs', function (Blueprint $table): void {
                if ($this->indexExists('campaign_logs', 'idx_campaign_logs_chat_status_campaign')) {
                    $table->dropIndex('idx_campaign_logs_chat_status_campaign');
                }
            });
        }

        if (Schema::hasTable('outbound_messages') && Schema::hasColumn('outbound_messages', 'sent_chat_id')) {
            Schema::table('outbound_messages', function (Blueprint $table): void {
                if ($this->indexExists('outbound_messages', 'idx_outbound_messages_sent_chat_template')) {
                    $table->dropIndex('idx_outbound_messages_sent_chat_template');
                }
            });
        }

        foreach ([
            'salla_webhook_deliveries',
            'zid_webhook_deliveries',
            'shopify_webhook_deliveries',
            'woocommerce_webhook_deliveries',
        ] as $deliveryTable) {
            if (! Schema::hasTable($deliveryTable) || ! Schema::hasColumn($deliveryTable, 'sent_chat_id')) {
                continue;
            }

            Schema::table($deliveryTable, function (Blueprint $table) use ($deliveryTable): void {
                $indexName = "idx_{$deliveryTable}_sent_chat";

                if ($this->indexExists($deliveryTable, $indexName)) {
                    $table->dropIndex($indexName);
                }
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
