<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('created_by');
            $table->string('status', 32)->default('uploaded');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->string('default_country_code', 8)->nullable();
            $table->json('selected_group_ids')->nullable();
            $table->json('report')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'contact_import_batches_org_status_idx');
            $table->index(['created_at'], 'contact_import_batches_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_batches');
    }
};

