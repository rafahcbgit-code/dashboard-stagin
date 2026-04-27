<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('contact_import_batches', 'group_assignment')) {
            Schema::table('contact_import_batches', function (Blueprint $table) {
                $table->json('group_assignment')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('contact_import_batches', 'group_assignment')) {
            Schema::table('contact_import_batches', function (Blueprint $table) {
                $table->dropColumn('group_assignment');
            });
        }
    }
};

