<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldTypes = ['meta.messaging_limit_increased', 'meta.messaging_limit_reduced'];
        $newType = 'meta.business_capability_changed';

        DB::table('notification_events')
            ->whereIn('type_key', $oldTypes)
            ->where('status', 'pending')
            ->orderBy('id')
            ->chunkById(100, function ($events) use ($newType) {
                foreach ($events as $event) {
                    $duplicate = DB::table('notification_events')
                        ->where('organization_id', $event->organization_id)
                        ->where('type_key', $newType)
                        ->where('event_key', $event->event_key)
                        ->exists();

                    DB::table('notification_events')->where('id', $event->id)->update($duplicate
                        ? ['status' => 'canceled', 'resolved_at' => now(), 'updated_at' => now()]
                        : ['type_key' => $newType, 'updated_at' => now()]);
                }
            });

        DB::table('notification_definitions')->whereIn('type_key', $oldTypes)->delete();
    }

    public function down(): void
    {
        // The original direction and administrator settings cannot be reconstructed from the merged type.
    }
};
