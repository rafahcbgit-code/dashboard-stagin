<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('chat_tickets', 'organization_id')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            });
        }

        // Backfill in small chunks to avoid locking the table
        $chunkSize = 200;
        $lastId = 0;

        while (true) {
            $rows = DB::table('chat_tickets')
                ->where('organization_id', null)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($chunkSize)
                ->get(['id', 'contact_id']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $contact = DB::table('contacts')->where('id', $row->contact_id)->first();
                if ($contact && $contact->organization_id) {
                    DB::table('chat_tickets')
                        ->where('id', $row->id)
                        ->update(['organization_id' => $contact->organization_id]);
                }
                $lastId = $row->id;
            }
        }

        if (!$this->indexExists('chat_tickets', 'idx_chat_tickets_org_status')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->index(['organization_id', 'status'], 'idx_chat_tickets_org_status');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('chat_tickets', 'idx_chat_tickets_org_status')) {
            Schema::table('chat_tickets', function (Blueprint $table) {
                $table->dropIndex('idx_chat_tickets_org_status');
            });
        }

        Schema::table('chat_tickets', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return !empty($indexes);
    }
};