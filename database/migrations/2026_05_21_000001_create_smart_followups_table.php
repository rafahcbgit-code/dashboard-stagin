<?php

use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\FlowBuilder\Models\Flow;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_followups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('organization_id')->index();
            $table->enum('type', ['rejoin_reminder'])->default('rejoin_reminder');
            $table->foreignId('flow_id')->constrained('flows')->cascadeOnDelete();
            $table->text('message');
            $table->unsignedInteger('reminder_minutes')->default(15);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'type', 'flow_id'], 'smart_followups_org_type_flow_unique');
            $table->index(['organization_id', 'type', 'is_enabled']);
        });

        $this->migrateLegacyRejoinReminders();
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_followups');
    }

    private function migrateLegacyRejoinReminders(): void
    {
        Organization::query()
            ->whereNotNull('metadata')
            ->chunkById(100, function ($organizations) {
                foreach ($organizations as $organization) {
                    $metadata = json_decode($organization->metadata, true);
                    if (!is_array($metadata)) {
                        continue;
                    }

                    $botSettings = $metadata['bot_settings'] ?? [];
                    if (empty($botSettings['flow_rejoin_enabled']) || empty($botSettings['flow_rejoin_flow_ids']) || !is_array($botSettings['flow_rejoin_flow_ids'])) {
                        continue;
                    }

                    $flowIds = Flow::where('organization_id', $organization->id)
                        ->whereNull('deleted_at')
                        ->whereIn('id', array_map('intval', $botSettings['flow_rejoin_flow_ids']))
                        ->pluck('id');

                    foreach ($flowIds as $flowId) {
                        DB::table('smart_followups')->updateOrInsert(
                            [
                                'organization_id' => $organization->id,
                                'type' => 'rejoin_reminder',
                                'flow_id' => $flowId,
                            ],
                            [
                                'uuid' => (string) \Ramsey\Uuid\Uuid::uuid4(),
                                'message' => $botSettings['flow_rejoin_message'] ?? "You have an active conversation with us. Reply to continue where you left off.",
                                'reminder_minutes' => max(5, (int) ($botSettings['flow_rejoin_reminder_minutes'] ?? 15)),
                                'is_enabled' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );
                    }
                }
            });
    }
};
