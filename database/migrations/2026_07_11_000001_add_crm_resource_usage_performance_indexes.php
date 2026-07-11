<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('subscriptions', ['organization_id', 'id'], 'idx_subscriptions_org_id');
        $this->addIndex('resource_addons', ['organization_id', 'resource', 'valid_until'], 'idx_resource_addons_org_resource_valid');
        $this->addIndex('subscription_usage_events', ['organization_id', 'resource', 'occurred_at'], 'idx_usage_events_org_resource_occurred');
        $this->addIndex('teams', ['organization_id', 'deleted_at'], 'idx_teams_org_deleted_crm');
        $this->addIndex('team_invites', ['organization_id', 'expire_at'], 'idx_team_invites_org_expire_crm');
    }

    public function down(): void
    {
        $this->dropIndex('team_invites', 'idx_team_invites_org_expire_crm');
        $this->dropIndex('teams', 'idx_teams_org_deleted_crm');
        $this->dropIndex('subscription_usage_events', 'idx_usage_events_org_resource_occurred');
        $this->dropIndex('resource_addons', 'idx_resource_addons_org_resource_valid');
        $this->dropIndex('subscriptions', 'idx_subscriptions_org_id');
    }

    private function addIndex(string $table, array $columns, string $index): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $index): void {
            $table->index($columns, $index);
        });
    }

    private function dropIndex(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $safeTable = str_replace("'", "''", $table);

            return collect(DB::select("PRAGMA index_list('{$safeTable}')"))
                ->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
