<?php

use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        SubscriptionPlan::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($plans): void {
                foreach ($plans as $plan) {
                    $metadata = json_decode((string) $plan->metadata, true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $metadata['resource_sharing_enabled'] = (int) ($metadata['resource_sharing_enabled'] ?? 0);

                    $plan->metadata = json_encode($metadata);
                    $plan->save();
                }
            });
    }

    public function down(): void
    {
        SubscriptionPlan::query()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($plans): void {
                foreach ($plans as $plan) {
                    $metadata = json_decode((string) $plan->metadata, true);
                    if (! is_array($metadata)) {
                        continue;
                    }

                    unset($metadata['resource_sharing_enabled']);

                    $plan->metadata = json_encode($metadata);
                    $plan->save();
                }
            });
    }
};
