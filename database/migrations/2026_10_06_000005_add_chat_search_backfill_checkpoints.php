<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_search_index_state', function (Blueprint $table) {
            $table->unsignedBigInteger('live_last_id')->default(0);
            $table->unsignedBigInteger('archive_last_id')->default(0);
            $table->timestamp('revocations_scanned_at')->nullable();
            $table->boolean('live_done')->default(false);
            $table->boolean('archive_done')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('chat_search_index_state', function (Blueprint $table) {
            $table->dropColumn([
                'live_last_id', 'archive_last_id', 'revocations_scanned_at',
                'live_done', 'archive_done',
            ]);
        });
    }
};
