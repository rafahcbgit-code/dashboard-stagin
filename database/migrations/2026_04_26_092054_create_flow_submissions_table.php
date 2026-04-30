<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('flow_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('submission_data')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'flow_id']);
            $table->index(['organization_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_submissions');
    }
};