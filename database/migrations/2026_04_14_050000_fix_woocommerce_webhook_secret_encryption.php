<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Encryption\DecryptException;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rows = DB::table('woocommerce_integrations')
            ->select('id', 'webhook_secret')
            ->whereNotNull('webhook_secret')
            ->get();

        foreach ($rows as $row) {
            $value = $row->webhook_secret;
            if (!is_string($value) || $value === '') {
                continue;
            }

            try {
                Crypt::decryptString($value);
                continue;
            } catch (DecryptException $exception) {
                DB::table('woocommerce_integrations')
                    ->where('id', $row->id)
                    ->update(['webhook_secret' => Crypt::encryptString($value)]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No safe down migration: encrypted values cannot be reliably reverted.
    }
};
