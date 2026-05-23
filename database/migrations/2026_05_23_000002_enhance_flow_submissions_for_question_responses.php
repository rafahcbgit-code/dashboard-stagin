<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_submissions', function (Blueprint $table) {
            $table->uuid('run_uuid')->nullable()->after('contact_id');
            $table->string('node_id')->nullable()->after('run_uuid');
            $table->string('variable_name')->nullable()->after('node_id');
            $table->text('question_text')->nullable()->after('variable_name');
            $table->string('input_type')->nullable()->after('question_text');
            $table->text('answer_value')->nullable()->after('input_type');
            $table->string('status')->default('answered')->after('answer_value');
            $table->timestamp('answered_at')->nullable()->after('status');

            $table->index(['organization_id', 'flow_id', 'run_uuid'], 'flow_submissions_org_flow_run_idx');
            $table->index(['organization_id', 'flow_id', 'variable_name'], 'flow_submissions_org_flow_variable_idx');
            $table->index(['organization_id', 'flow_id', 'created_at'], 'flow_submissions_org_flow_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flow_submissions', function (Blueprint $table) {
            $table->dropIndex('flow_submissions_org_flow_run_idx');
            $table->dropIndex('flow_submissions_org_flow_variable_idx');
            $table->dropIndex('flow_submissions_org_flow_created_idx');

            $table->dropColumn([
                'run_uuid',
                'node_id',
                'variable_name',
                'question_text',
                'input_type',
                'answer_value',
                'status',
                'answered_at',
            ]);
        });
    }
};
