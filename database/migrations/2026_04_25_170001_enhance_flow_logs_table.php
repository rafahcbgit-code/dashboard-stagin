<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enhance flow_logs with more context for analytics
        Schema::table('flow_logs', function (Blueprint $table) {
            $table->foreignId('contact_id')->nullable()->after('flow_id')->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->after('contact_id')->constrained()->nullOnDelete();
            $table->foreignId('chat_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->string('trigger_group_id')->nullable()->after('chat_id');
            $table->string('trigger_name')->nullable()->after('trigger_group_id');
            $table->string('trigger_type')->nullable()->after('trigger_name');
            $table->string('match_mode')->nullable()->after('trigger_type');
            $table->string('matched_keyword')->nullable()->after('match_mode');
            $table->string('status')->default('completed')->after('matched_keyword');
            $table->string('node_reached')->nullable()->after('status');
            $table->integer('nodes_processed')->default(0)->after('node_reached');
        });
    }

    public function down(): void
    {
        Schema::table('flow_logs', function (Blueprint $table) {
            $table->dropColumn([
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
            ]);
        });
    }
};