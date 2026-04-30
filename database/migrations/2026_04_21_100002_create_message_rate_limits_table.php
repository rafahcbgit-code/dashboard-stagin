<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessageRateLimitsTable extends Migration
{
    public function up()
    {
        Schema::create('message_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('trigger_group_id');
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('trigger_count')->default(0);
            $table->timestamps();

            $table->foreign('trigger_group_id')->references('id')->on('trigger_groups')->cascadeOnDelete();
            $table->unique(['organization_id', 'contact_id', 'trigger_group_id'], 'rate_limits_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('message_rate_limits');
    }
}