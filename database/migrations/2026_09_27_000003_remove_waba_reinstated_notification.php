<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $type = 'meta.waba_reinstated';

            DB::table('notification_events')->where('type_key', $type)->where('status', 'pending')->update([
                'status' => 'canceled',
                'resolved_at' => now(),
                'queued_at' => null,
                'updated_at' => now(),
            ]);

            DB::table('notification_deliveries')
                ->whereIn('event_id', DB::table('notification_events')->select('id')->where('type_key', $type))
                ->where('status', 'pending')
                ->update(['status' => 'canceled', 'updated_at' => now()]);

            DB::table('notification_definitions')->where('type_key', $type)->delete();
        });
    }

    public function down(): void
    {
        // Deleted administrator settings cannot be reconstructed safely.
    }
};
