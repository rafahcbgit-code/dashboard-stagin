<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\MissedChatStatusUpdate;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessMissedStatusUpdatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes timeout
    public $tries = 3;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Clean up old processing records that might be stuck (older than 15 minutes)
            $this->cleanupStuckRecords();

            // Get 1000 unprocessed records
            $missedUpdates = MissedChatStatusUpdate::notProcessed()
                ->orderBy('created_at', 'asc')
                ->limit(1000)
                ->get();

            if ($missedUpdates->isEmpty()) {
                return;
            }

            Log::channel('webhook')->info('Processing missed status updates', [
                'count' => $missedUpdates->count()
            ]);

            // Mark all selected records as processing
            $updateIds = $missedUpdates->pluck('id')->toArray();
            MissedChatStatusUpdate::whereIn('id', $updateIds)
                ->update([
                    'state' => MissedChatStatusUpdate::STATE_PROCESSING,
                    'updated_at' => now()
                ]);

            // Process each record by dispatching individual jobs
            foreach ($missedUpdates as $missedUpdate) {
                ProcessSingleMissedStatusJob::dispatch($missedUpdate->id)
                    ->onQueue('webhook-status');
            }

            Log::channel('webhook')->info('Dispatched missed status update jobs', [
                'dispatched' => count($updateIds)
            ]);

        } catch (\Exception $e) {
            Log::error('Error in ProcessMissedStatusUpdatesJob: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    private function cleanupStuckRecords()
    {
        // Reset records that have been "processing" for more than 15 minutes
        $stuckCount = MissedChatStatusUpdate::processing()
            ->olderThan(15)
            ->update([
                'state' => MissedChatStatusUpdate::STATE_NOT_PROCESSED,
                'updated_at' => now()
            ]);

        if ($stuckCount > 0) {
            Log::channel('webhook')->warning('Reset stuck processing records', [
                'reset_count' => $stuckCount
            ]);
        }
    }
}
