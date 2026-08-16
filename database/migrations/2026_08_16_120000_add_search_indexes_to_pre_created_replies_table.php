<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_created_replies', function (Blueprint $table) {
            $table->index(
                ['organization_id', 'deleted_at', 'created_at', 'id'],
                'pre_created_replies_listing_index'
            );
            $table->fullText('title', 'pre_created_replies_title_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('pre_created_replies', function (Blueprint $table) {
            $table->dropFullText('pre_created_replies_title_fulltext');
            $table->dropIndex('pre_created_replies_listing_index');
        });
    }
};
