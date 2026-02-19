<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('web_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('logo')->nullable();
            $table->string('whatsapp_phone', 20);
            $table->string('header_text')->nullable();
            $table->string('header_subtext')->nullable();
            $table->text('widget_text')->nullable();
            $table->string('button_text')->nullable();
            $table->enum('widget_type', ['start_button', 'input_field'])->default('start_button');
            $table->string('input_placeholder')->nullable();
            $table->string('button_color', 7)->default('#25D366');
            $table->string('header_color', 7)->default('#128C7E');
            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_widgets');
    }
};
