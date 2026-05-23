<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_bot_reenables', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('reenable_at');
            $table->index('reenable_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_bot_reenables');
    }
};