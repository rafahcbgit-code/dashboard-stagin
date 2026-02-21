<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_flow_responses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            // Local reference to the meta_flows record
            $table->foreignId('meta_flow_id')->constrained('meta_flows')->onDelete('cascade');
            // The contact who submitted the flow
            $table->unsignedBigInteger('contact_id');
            // Token we generated when sending the flow; used to match this response to the right flow
            $table->string('flow_token')->nullable();
            // Raw JSON string received from Meta in the nfm_reply webhook
            $table->text('response_json')->nullable();
            $table->timestamps();

            $table->index('organization_id');
            $table->index('contact_id');
            $table->index('flow_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_flow_responses');
    }
};
