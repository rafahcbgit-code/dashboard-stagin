<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_flows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id');
            // The ID that Meta assigns when the flow is created on their platform
            $table->string('meta_flow_id')->nullable();
            $table->string('name');
            // JSON array of Meta categories e.g. ["SIGN_UP", "LEAD_GENERATION"]
            $table->json('categories')->nullable();
            // Optional URL for dynamic data exchange during a flow session
            $table->string('endpoint_uri')->nullable();
            // The raw Flow JSON uploaded to Meta (defines screens and components)
            $table->longText('asset_content')->nullable();
            // URL Meta provides to preview the flow before publishing
            $table->string('preview_url')->nullable();
            // Mirrors Meta flow statuses: draft, published, deprecated, blocked, throttled
            $table->enum('status', ['draft', 'published', 'deprecated', 'blocked', 'throttled'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index('organization_id');
            $table->index('meta_flow_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_flows');
    }
};
