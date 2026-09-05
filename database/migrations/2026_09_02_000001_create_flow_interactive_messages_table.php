<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_interactive_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('flow_id');
            $table->string('node_id');
            $table->uuid('flow_run_uuid');
            $table->string('outbound_wam_id', 191);
            $table->string('reply_type', 32);
            $table->json('options');
            $table->timestamps();

            $table->unique('outbound_wam_id', 'flow_interactive_messages_wam_unique');
            $table->index(
                ['organization_id', 'contact_id', 'outbound_wam_id'],
                'flow_interactive_messages_lookup'
            );
        });

        Schema::create('flow_portal_reply_transitions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('interactive_message_id');
            $table->unsignedBigInteger('inbound_chat_id');
            $table->string('inbound_wam_id', 191);
            $table->unsignedBigInteger('from_flow_id')->nullable();
            $table->unsignedBigInteger('to_flow_id');
            $table->timestamps();

            $table->unique('inbound_chat_id', 'flow_portal_transitions_chat_unique');
            $table->unique('inbound_wam_id', 'flow_portal_transitions_wam_unique');
            $table->index(['organization_id', 'contact_id'], 'flow_portal_transitions_contact');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_portal_reply_transitions');
        Schema::dropIfExists('flow_interactive_messages');
    }
};
