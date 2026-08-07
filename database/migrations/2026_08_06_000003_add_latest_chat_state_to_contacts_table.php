<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the awaiting-reply state outside contacts.
     *
     * contacts has a FULLTEXT index, and altering it can require ALGORITHM=COPY
     * on some MySQL versions. Creating this small companion table is metadata-only
     * and therefore safe to deploy even when contacts contains millions of rows.
     */
    public function up(): void
    {
        if (Schema::hasTable('contact_chat_states')) {
            return;
        }

        Schema::create('contact_chat_states', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('latest_chat_id');
            $table->string('latest_chat_type', 16)->nullable();
            $table->timestamp('latest_chat_created_at')->nullable();

            $table->index(
                ['organization_id', 'latest_chat_type', 'latest_chat_created_at', 'contact_id'],
                'idx_contact_chat_states_awaiting_reply'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_chat_states');
    }
};
