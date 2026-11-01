<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'idx_chats_export_org_contact_created_id';

    public function up(): void
    {
        if (! $this->indexExists()) {
            Schema::table('chats', function (Blueprint $table) {
                $table->index(['organization_id', 'contact_id', 'created_at', 'id'], self::INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('chats', function (Blueprint $table) {
                $table->dropIndex(self::INDEX);
            });
        }
    }

    private function indexExists(): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'chats')
            ->where('index_name', self::INDEX)
            ->exists();
    }
};
