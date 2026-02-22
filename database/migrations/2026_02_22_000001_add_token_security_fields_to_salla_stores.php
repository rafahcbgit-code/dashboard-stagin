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
        Schema::table('salla_stores', function (Blueprint $table) {
            // Token security fields
            $table->timestamp('refresh_token_expires_at')->nullable()->after('expires_at');
            $table->timestamp('refresh_token_used_at')->nullable()->after('refresh_token_expires_at');
            $table->timestamp('last_refresh_at')->nullable()->after('refresh_token_used_at');
            
            // Version for token invalidation
            $table->unsignedInteger('token_version')->default(1)->after('last_refresh_at');
            
            // Revocation status
            $table->boolean('is_revoked')->default(false)->after('token_version');
            $table->timestamp('revoked_at')->nullable()->after('is_revoked');
            $table->string('revocation_reason')->nullable()->after('revoked_at');
            
            // Index for performance
            $table->index(['merchant_id', 'organization_id', 'is_revoked']);
            $table->index('expires_at');
            $table->index('refresh_token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salla_stores', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'organization_id', 'is_revoked']);
            $table->dropIndex(['expires_at']);
            $table->dropIndex(['refresh_token_expires_at']);
            
            $table->dropColumn([
                'refresh_token_expires_at',
                'refresh_token_used_at',
                'last_refresh_at',
                'token_version',
                'is_revoked',
                'revoked_at',
                'revocation_reason'
            ]);
        });
    }
};
