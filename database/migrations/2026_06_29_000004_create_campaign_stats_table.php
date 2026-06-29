<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_stats')) {
            return;
        }

        Schema::create('campaign_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('total_message_count')->default(0);
            $table->unsignedBigInteger('total_sent_count')->default(0);
            $table->unsignedBigInteger('total_delivered_count')->default(0);
            $table->unsignedBigInteger('total_failed_count')->default(0);
            $table->unsignedBigInteger('total_read_count')->default(0);
            $table->unsignedBigInteger('processing_count')->default(0);
            $table->unsignedBigInteger('queued_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'campaign_id'], 'idx_campaign_stats_org_campaign');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_stats');
    }
};
