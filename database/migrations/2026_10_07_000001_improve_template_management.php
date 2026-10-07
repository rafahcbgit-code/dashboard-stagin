<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->json('internal_metadata')->nullable()->after('metadata');
            $table->boolean('has_unsubscribe_action')->default(false)->after('internal_metadata');
            $table->timestamp('last_used_at')->nullable()->after('disabled_at');
            $table->timestamp('last_synced_at')->nullable()->after('last_used_at');
            $table->timestamp('remote_missing_at')->nullable()->after('last_synced_at');
            $table->index(['organization_id', 'deleted_at', 'name'], 'idx_templates_org_name');
            $table->index(['organization_id', 'deleted_at', 'category', 'status', 'updated_at'], 'idx_templates_filters');
            $table->index(['organization_id', 'has_unsubscribe_action', 'category'], 'idx_templates_opt_out');
        });

        Schema::create('template_status_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('template_id');
            $table->string('old_status', 64)->nullable();
            $table->string('new_status', 64)->nullable();
            $table->string('old_category', 64)->nullable();
            $table->string('new_category', 64)->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 32);
            $table->json('context')->nullable();
            $table->char('fingerprint', 64)->unique();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('template_id')->references('id')->on('templates')->cascadeOnDelete();
            $table->index(['organization_id', 'template_id', 'occurred_at'], 'idx_template_events_timeline');
        });

        Schema::create('template_sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->string('waba_hash', 64);
            $table->string('status', 32);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('status_changed_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('remote_total')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'created_at'], 'idx_template_sync_runs_org');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_sync_runs');
        Schema::dropIfExists('template_status_events');
        Schema::table('templates', function (Blueprint $table) {
            $table->dropIndex('idx_templates_org_name');
            $table->dropIndex('idx_templates_filters');
            $table->dropIndex('idx_templates_opt_out');
            $table->dropColumn(['internal_metadata', 'has_unsubscribe_action', 'last_used_at', 'last_synced_at', 'remote_missing_at']);
        });
    }
};
