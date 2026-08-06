<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex(['organization_id', 'email'], 'idx_team_invites_org_email');
        $this->addIndex(['code', 'expire_at'], 'idx_team_invites_code_expire');
    }

    public function down(): void
    {
        $this->dropIndex('idx_team_invites_code_expire');
        $this->dropIndex('idx_team_invites_org_email');
    }

    private function addIndex(array $columns, string $name): void
    {
        if (! Schema::hasTable('team_invites') || $this->indexExists($name)) {
            return;
        }

        Schema::table('team_invites', function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function dropIndex(string $name): void
    {
        if (! Schema::hasTable('team_invites') || ! $this->indexExists($name)) {
            return;
        }

        Schema::table('team_invites', function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function indexExists(string $name): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('team_invites')"))
                ->contains(fn ($index) => ($index->name ?? null) === $name);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'team_invites')
            ->where('index_name', $name)
            ->exists();
    }
};
