<?php

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatStatusLog;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use App\Models\MissedChatStatusUpdate;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

class ProcessSingleMissedStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $missedUpdateId;

    public $timeout = 60;

    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct($missedUpdateId)
    {
        $this->missedUpdateId = $missedUpdateId;
        $this->onQueue('webhook-status');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get the missed update record
            $missedUpdate = MissedChatStatusUpdate::find($this->missedUpdateId);
            
            if (!$missedUpdate || $missedUpdate->state !== MissedChatStatusUpdate::STATE_PROCESSING) {
                return;
            }

            // Try to find the chat
            $chat = Chat::where('wam_id', $missedUpdate->wam_id)
                        ->where('organization_id', $missedUpdate->organization_id)
                        ->first();

            if ($chat) {
                // Chat found - update status and mark as processed
                $this->updateChatStatus($chat, $missedUpdate);
                $this->markAsProcessed($missedUpdate);

                /* Log::channel('webhook')->info('Successfully processed missed status update', [
                    'missed_update_id' => $missedUpdate->id,
                    'retry_count' => $missedUpdate->retry_count,
                    'wam_id' => $missedUpdate->wam_id
                ]); */
            } else {
                // Chat still not found - mark back to not processed for retry later
                $this->incrementRetryAndSchedule($missedUpdate);

                /* Log::channel('webhook')->warning('Chat not found, scheduling retry', [
                    'missed_update_id' => $missedUpdate->id,
                    'retry_count' => $missedUpdate->retry_count + 1,
                    'wam_id' => $missedUpdate->wam_id,
                    'next_retry_at' => MissedChatStatusUpdate::calculateNextRetryTime($missedUpdate->retry_count + 1)
                ]); */
            }

        } catch (\Exception $e) {
            Log::channel('webhook')->error('Error in ProcessSingleMissedStatusJob', [
                'missed_update_id' => $this->missedUpdateId,
                'error' => $e->getMessage()
            ]);

            if (isset($missedUpdate)) {
                $this->incrementRetryAndSchedule($missedUpdate);
            }
        }
    }

    private function updateChatStatus(Chat $chat, MissedChatStatusUpdate $missedUpdate)
    {
        try {
            // Check if status update is meaningful
            if ($this->shouldUpdateStatus($chat->status, $missedUpdate->status)) {
                Chat::updateStatusIfProgressed($chat->id, $missedUpdate->status);
            }

            // Create status log
            $chatStatusLog = new ChatStatusLog;
            $chatStatusLog->chat_id = $chat->id;
            $chatStatusLog->metadata = $missedUpdate->payload;
            $chatStatusLog->created_at = $missedUpdate->created_at;
            $chatStatusLog->save();

        } catch (\Exception $e) {
            Log::channel('webhook')->error('Failed to update chat status from missed update', [
                'chat_id' => $chat->id,
                'missed_update_id' => $missedUpdate->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function shouldUpdateStatus($currentStatus, $newStatus)
    {
        $statusOrder = [
            'sent' => 1,
            'delivered' => 2,
            'read' => 3,
            'failed' => 4
        ];

        if (!isset($statusOrder[$newStatus])) {
            return false;
        }

        if (!isset($statusOrder[$currentStatus])) {
            return true;
        }

        return $statusOrder[$newStatus] > $statusOrder[$currentStatus] || $newStatus === 'failed';
    }

    private function markAsProcessed(MissedChatStatusUpdate $missedUpdate)
    {
        $missedUpdate->update([
            'state' => MissedChatStatusUpdate::STATE_PROCESSED,
            'updated_at' => now()
        ]);
    }

    private function markAsNotProcessed(MissedChatStatusUpdate $missedUpdate)
    {
        $missedUpdate->update([
            'state' => MissedChatStatusUpdate::STATE_NOT_PROCESSED,
            'updated_at' => now()
        ]);
    }

    private function incrementRetryAndSchedule(MissedChatStatusUpdate $missedUpdate)
    {
        $newRetryCount = $missedUpdate->retry_count + 1;
        
        if ($newRetryCount >= MissedChatStatusUpdate::MAX_RETRIES) {
            // Mark as failed if max retries exceeded
            $missedUpdate->update([
                'state' => MissedChatStatusUpdate::STATE_FAILED,
                'retry_count' => $newRetryCount,
                'updated_at' => now()
            ]);
        } else {
            // Schedule next retry with exponential backoff
            $missedUpdate->update([
                'state' => MissedChatStatusUpdate::STATE_NOT_PROCESSED,
                'retry_count' => $newRetryCount,
                'next_retry_at' => MissedChatStatusUpdate::calculateNextRetryTime($newRetryCount),
                'updated_at' => now()
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::channel('webhook')->error('ProcessSingleMissedStatusJob failed permanently', [
            'missed_update_id' => $this->missedUpdateId,
            'error' => $exception->getMessage()
        ]);

        try {
            $missedUpdate = MissedChatStatusUpdate::find($this->missedUpdateId);
            if ($missedUpdate) {
                $this->incrementRetryAndSchedule($missedUpdate);
            }
        } catch (\Exception $e) {
            Log::channel('webhook')->error('Failed to update retry count', [
                'missed_update_id' => $this->missedUpdateId
            ]);
        }
    }
}
