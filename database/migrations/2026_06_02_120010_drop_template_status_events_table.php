<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('template_status_events');
    }

    public function down(): void
    {
        // The template status event history table was intentionally removed.
    }
};
