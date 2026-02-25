<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_flows', function (Blueprint $table) {
            $table->string('google_sheet_url')->nullable()->after('preview_url');
            $table->string('google_sheet_tab')->nullable()->after('google_sheet_url');
            // [{"flow_field":"full_name","sheet_column":"Name"}, ...]
            // Special flow_field tokens: __timestamp, __contact_phone, __contact_name, __flow_token
            $table->json('google_sheet_mapping')->nullable()->after('google_sheet_tab');
            $table->boolean('google_sheet_enabled')->default(false)->after('google_sheet_mapping');
        });
    }

    public function down(): void
    {
        Schema::table('meta_flows', function (Blueprint $table) {
            $table->dropColumn(['google_sheet_url', 'google_sheet_tab', 'google_sheet_mapping', 'google_sheet_enabled']);
        });
    }
};
