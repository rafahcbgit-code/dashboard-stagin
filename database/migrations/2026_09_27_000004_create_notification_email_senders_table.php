<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_email_senders', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('host', 255);
            $table->unsignedSmallInteger('port');
            $table->string('encryption', 10);
            $table->string('username', 255);
            $table->text('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_email_senders');
    }
};
