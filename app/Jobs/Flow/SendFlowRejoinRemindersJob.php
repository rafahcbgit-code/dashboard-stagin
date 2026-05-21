<?php

namespace App\Jobs\Flow;

use App\Models\SmartFollowup;
use App\Services\BotSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Models\FlowUserData;

class SendFlowRejoinRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     * Scans for stalled flows and dispatches individual message jobs.
     */
    public function handle(): void
    {
        try {
            $botSettings = app(BotSettingsService::class);
            $dispatchedCount = 0;

            // Process in chunks of 200 to keep memory bounded
            FlowUserData::whereNull('rejoin_sent_at')
                ->whereNotNull('flow_id')
                ->chunkById(200, function ($stalledFlows) use ($botSettings, &$dispatchedCount) {
                    // Group by organization to batch settings and followup lookups
                    $orgSettings = [];
                    $orgFollowups = [];

                    foreach ($stalledFlows as $flowData) {
                        try {
                            $flow = $flowData->flow;
                            if (!$flow) {
                                continue;
                            }

                            $organizationId = $flow->organization_id;

                            // Load and cache org settings for this chunk
                            if (!isset($orgSettings[$organizationId])) {
                                $orgSettings[$organizationId] = [
                                    'enabled' => $botSettings->isAutomationEnabled($organizationId),
                                    'timeout_minutes' => $botSettings->getFlowTimeoutMinutes($organizationId),
                                ];

                                $orgFollowups[$organizationId] = SmartFollowup::forOrganization($organizationId)
                                    ->rejoinReminder()
                                    ->enabled()
                                    ->get()
                                    ->keyBy('flow_id');
                            }

                            if (!$orgSettings[$organizationId]['enabled']) {
                                continue;
                            }

                            $followup = $orgFollowups[$organizationId]->get((int) $flowData->flow_id);
                            if (!$followup) {
                                continue;
                            }

                            if (!$flowData->updated_at) {
                                continue;
                            }

                            $inactiveMinutes = $flowData->updated_at->diffInMinutes(now());

                            // Not enough inactivity yet
                            if ($inactiveMinutes < $followup->reminder_minutes) {
                                continue;
                            }

                            // Flow has already expired
                            if ($inactiveMinutes >= $orgSettings[$organizationId]['timeout_minutes']) {
                                continue;
                            }

                            $contact = $flowData->contact;
                            if (!$contact) {
                                continue;
                            }

                            // Dispatch individual send job — runs on queue, not synchronously
                            SendFlowRejoinMessageJob::dispatch(
                                $flowData->id,
                                $organizationId,
                                $contact->uuid,
                                $followup->message
                            );

                            $dispatchedCount++;
                        } catch (\Throwable $e) {
                            Log::error('FlowRejoinReminder: failed to dispatch for flow data ' . $flowData->id, [
                                'error' => $e->getMessage(),
                            ]);
                            continue;
                        }
                    }
                });

            if ($dispatchedCount > 0) {
                Log::info("FlowRejoinReminder: dispatched {$dispatchedCount} reminder jobs");
            }
        } catch (\Throwable $e) {
            Log::error('FlowRejoinReminder: job failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
