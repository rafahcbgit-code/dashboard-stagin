<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plans')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($plans): void {
                foreach ($plans as $plan) {
                    $metadata = json_decode((string) $plan->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];

                    $metadata['template_messages_limit'] = $metadata['template_messages_limit']
                        ?? $metadata['message_limit']
                        ?? '-1';
                    $metadata['conversations_limit'] = $metadata['conversations_limit']
                        ?? $metadata['message_limit']
                        ?? '-1';
                    $metadata['contacts_limit'] = $metadata['contacts_limit'] ?? '-1';
                    $metadata['team_limit'] = $metadata['team_limit'] ?? '-1';
                    $metadata['organizations_limit'] = $metadata['organizations_limit'] ?? '-1';

                    DB::table('subscription_plans')
                        ->where('id', $plan->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }

    public function down(): void
    {
        DB::table('subscription_plans')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($plans): void {
                foreach ($plans as $plan) {
                    $metadata = json_decode((string) $plan->metadata, true);
                    if (! is_array($metadata)) {
                        continue;
                    }

                    unset(
                        $metadata['template_messages_limit'],
                        $metadata['conversations_limit'],
                        $metadata['organizations_limit']
                    );

                    DB::table('subscription_plans')
                        ->where('id', $plan->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }
};
