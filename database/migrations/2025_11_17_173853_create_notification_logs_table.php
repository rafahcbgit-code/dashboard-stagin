<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('notification_type', 50); // 'new_message', 'assignment', 'status_update'
            $table->json('payload');
            $table->json('fcm_response')->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->boolean('delivered')->default(0);
            $table->boolean('clicked')->default(0);

            $table->index(['user_id', 'organization_id']);
            $table->index('notification_type');
            $table->index('sent_at');
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
