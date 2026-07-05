<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'billing_organization_id')) {
                $table->unsignedBigInteger('billing_organization_id')->nullable()->after('metadata');
                $table->foreign('billing_organization_id')
                    ->references('id')
                    ->on('organizations')
                    ->nullOnDelete();
                $table->index('billing_organization_id', 'organizations_billing_organization_id_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'billing_organization_id')) {
                $table->dropForeign(['billing_organization_id']);
                $table->dropIndex('organizations_billing_organization_id_index');
                $table->dropColumn('billing_organization_id');
            }
        });
    }
};
