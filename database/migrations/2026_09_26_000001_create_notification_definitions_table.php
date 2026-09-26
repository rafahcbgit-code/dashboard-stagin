<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('type_key', 100)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('delivery_mode', 20)->default('dashboard');
            $table->text('dashboard_template');
            $table->string('email_subject', 191);
            $table->text('email_body');
            $table->string('sender_email', 191)->nullable();
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_definitions');
    }
};
