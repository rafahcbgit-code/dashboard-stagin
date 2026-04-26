<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('status', 32)->default('pending');
            $table->boolean('is_removed')->default(false);
            $table->json('raw_data')->nullable();
            $table->json('mapped_data')->nullable();
            $table->json('normalized_data')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->foreign('contact_import_batch_id', 'contact_import_rows_batch_fk')
                ->references('id')
                ->on('contact_import_batches')
                ->onDelete('cascade');

            $table->index(['contact_import_batch_id', 'status'], 'contact_import_rows_batch_status_idx');
            $table->index(['contact_import_batch_id', 'is_removed'], 'contact_import_rows_batch_removed_idx');
            $table->index(['row_number'], 'contact_import_rows_row_number_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_import_rows');
    }
};

