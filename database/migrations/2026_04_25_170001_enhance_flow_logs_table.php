<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $existingColumns = collect(Schema::getColumnListing('flow_logs'))->map(fn($col) => strtolower($col))->toArray();

        Schema::table('flow_logs', function (Blueprint $table) use ($existingColumns) {
            if (!in_array('contact_id', $existingColumns)) {
                $table->foreignId('contact_id')->nullable()->after('flow_id')->constrained()->nullOnDelete();
            }
            if (!in_array('organization_id', $existingColumns)) {
                $table->foreignId('organization_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
            }
            if (!in_array('chat_id', $existingColumns)) {
                $table->foreignId('chat_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }
            if (!in_array('trigger_group_id', $existingColumns)) {
                $table->string('trigger_group_id')->nullable()->after('chat_id');
            }
            if (!in_array('trigger_name', $existingColumns)) {
                $table->string('trigger_name')->nullable()->after('trigger_group_id');
            }
            if (!in_array('trigger_type', $existingColumns)) {
                $table->string('trigger_type')->nullable()->after('trigger_name');
            }
            if (!in_array('match_mode', $existingColumns)) {
                $table->string('match_mode')->nullable()->after('trigger_type');
            }
            if (!in_array('matched_keyword', $existingColumns)) {
                $table->string('matched_keyword')->nullable()->after('match_mode');
            }
            if (!in_array('status', $existingColumns)) {
                $table->string('status')->default('completed')->after('matched_keyword');
            }
            if (!in_array('node_reached', $existingColumns)) {
                $table->string('node_reached')->nullable()->after('status');
            }
            if (!in_array('nodes_processed', $existingColumns)) {
                $table->integer('nodes_processed')->default(0)->after('node_reached');
            }
        });
    }

    public function down(): void
    {
        Schema::table('flow_logs', function (Blueprint $table) {
            $existingColumns = collect(Schema::getColumnListing('flow_logs'))->map(fn($col) => strtolower($col))->toArray();

            $toDrop = collect([
                'contact_id',
                'organization_id',
                'chat_id',
                'trigger_group_id',
                'trigger_name',
                'trigger_type',
                'match_mode',
                'matched_keyword',
                'status',
                'node_reached',
                'nodes_processed',
            ])->filter(fn($col) => in_array($col, $existingColumns))->values()->toArray();

            if (!empty($toDrop)) {
                // Drop foreign keys first for foreignId columns
                foreach (['contact_id', 'organization_id', 'chat_id'] as $fk) {
                    if (in_array($fk, $toDrop)) {
                        try {
                            $table->dropForeign([$fk]);
                        } catch (\Exception $e) {
                            // Foreign key may not exist
                        }
                    }
                }
                $table->dropColumn($toDrop);
            }
        });
    }
};