<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_search_revocations', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('original_wam_id', 128);
            $table->primary(['organization_id', 'contact_id', 'original_wam_id'], 'chat_search_revocations_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_search_revocations');
    }
};
