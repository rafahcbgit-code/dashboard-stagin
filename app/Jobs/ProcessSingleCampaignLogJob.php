<?php

namespace App\Jobs;

use App\Jobs\RetryCampaignLogJob;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use App\Models\Organization;
use App\Services\WhatsappService;
use App\Traits\TemplateTrait;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSingleCampaignLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TemplateTrait, Batchable;

    private $campaignLog;
    private $organizationId;
    private $whatsappService;
    
    public $timeout = 300; // 5 minutes timeout for single message
    public $tries = 3;

    public function __construct(CampaignLog $campaignLog)
    {
        $this->campaignLog = $campaignLog;
    }

    public function handle()
    {
        try {
            $campaign_user_id = Campaign::find($this->campaignLog->campaign_id)?->created_by;
            
            // Use a single transaction to ensure atomicity and proper timing
            DB::transaction(function() use ($campaign_user_id) {
                // Lock the log for update to prevent duplicate processing
                $lockedLog = CampaignLog::where('id', $this->campaignLog->id)
                    ->whereIn('status', ['pending', 'failed'])
                    ->lockForUpdate()
                    ->first();
                       
                if (!$lockedLog) {
                    return; // Log already processed or doesn't exist
                }

                // Mark as ongoing to prevent duplicate processing
                $lockedLog->status = 'ongoing';
                $lockedLog->save();
        
                $this->organizationId = $this->campaignLog->campaign->organization_id;
                $this->initializeWhatsappService();
        
                $template = $this->buildTemplateRequest($this->campaignLog->campaign_id, $this->campaignLog->contact);
                
                // Send the template message
                $responseObject = $this->whatsappService->sendTemplateMessage2(
                    $this->campaignLog->contact->uuid, 
                    $template,
                    $campaign_user_id, 
                    $this->campaignLog->campaign_id
                );

                // Update the log status immediately within the same transaction
                $this->updateLogStatus($lockedLog, $responseObject);
            });

            // Small delay to ensure database changes are fully committed
            // before WhatsApp webhook status updates can arrive
            usleep(100000); // 100ms delay

        } catch (\Exception $e) {
            Log::error('Error processing campaign log ' . $this->campaignLog->id . ': ' . $e->getMessage(), [
                'campaign_log_id' => $this->campaignLog->id,
                'contact_id' => $this->campaignLog->contact_id ?? null,
                'campaign_id' => $this->campaignLog->campaign_id ?? null,
                'stack_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function updateLogStatus(CampaignLog $log, $responseObject)
    {
        try {
            $log->chat_id = $responseObject->data->chat->id ?? null;
            $log->status = ($responseObject->success === true) ? 'success' : 'failed';
            
            // Clean up response object to avoid circular references
            $cleanResponse = clone $responseObject;
            unset($cleanResponse->success);
            if (property_exists($cleanResponse, 'data') && property_exists($cleanResponse->data, 'chat')) {
                unset($cleanResponse->data->chat);
            }
            
            $log->metadata = json_encode($cleanResponse);
            $log->updated_at = now();
            $log->save();

            // Log successful processing for debugging
            if ($log->status === 'success') {
                Log::channel('campaign')->info('Campaign message sent successfully', [
                    'campaign_log_id' => $log->id,
                    'chat_id' => $log->chat_id,
                    'wam_id' => $responseObject->data->messages[0]->id ?? 'unknown'
                ]);
            } else {
                Log::channel('campaign')->warning('Campaign message failed', [
                    'campaign_log_id' => $log->id,
                    'error' => $cleanResponse->message ?? 'Unknown error'
                ]);
            }

            // Check if campaign is completed (outside of transaction for performance)
            $this->checkAndUpdateCampaignStatus($log->campaign_id);
            
        } catch (\Exception $e) {
            Log::error('Error updating campaign log status: ' . $e->getMessage(), [
                'campaign_log_id' => $log->id,
                'response_object' => json_encode($responseObject)
            ]);
            throw $e;
        }
    }

    protected function checkAndUpdateCampaignStatus($campaignId)
    {
        try {
            $campaign = Campaign::find($campaignId);
            if (!$campaign) {
                return;
            }

            $orgMetadata = json_decode($campaign->organization->metadata ?? '{}', true);
            $retryEnabled = $orgMetadata['campaigns']['enable_resend'] ?? false;
            $retryIntervals = $orgMetadata['campaigns']['resend_intervals'] ?? [];
    
            $pendingOrOngoing = CampaignLog::where('campaign_id', $campaignId)
                ->whereIn('status', ['pending', 'ongoing'])
                ->exists();

            if ($pendingOrOngoing) {
                return; // Still processing logs
            }

            if ($retryEnabled) {
                $logs = CampaignLog::where('campaign_id', $campaignId)->get();
        
                foreach ($logs as $log) {
                    $retryCount = $log->retries()->count();
                    $maxRetries = count($retryIntervals);

                    // Skip if already retried max times
                    if ($retryCount >= $maxRetries) {
                        continue;
                    }

                    // If log failed and hasn't reached max retries, schedule retry
                    if ($log->status === 'failed' && $retryCount < $maxRetries) {
                        $delayMinutes = $retryIntervals[$retryCount] ?? null;

                        if ($delayMinutes !== null) {
                            RetryCampaignLogJob::dispatch($campaign->organization_id, $log->id, $retryCount)
                                ->onQueue('campaign-messages')
                                ->delay(now()->addMinutes($delayMinutes));
                            
                            Log::channel('campaign')->info('Scheduled retry for failed campaign log', [
                                'campaign_log_id' => $log->id,
                                'retry_count' => $retryCount,
                                'delay_minutes' => $delayMinutes
                            ]);
                        }
                    }  
                }
            } else {
                // Check again after potential changes
                $unprocessed = CampaignLog::where('campaign_id', $campaignId)
                    ->whereIn('status', ['pending', 'ongoing'])
                    ->exists();

                if (!$unprocessed) {
                    $campaign->update(['status' => 'completed']);
                    
                    Log::channel('campaign')->info('Campaign completed', [
                        'campaign_id' => $campaignId,
                        'campaign_name' => $campaign->name
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error checking campaign status: ' . $e->getMessage(), [
                'campaign_id' => $campaignId
            ]);
        }
    }

    private function initializeWhatsappService()
    {
        try {
            // Use caching to reduce database queries for organization metadata
            $config = cache()->remember("organization.{$this->organizationId}.metadata", 600, function() {
                $org = Organization::find($this->organizationId);
                return $org ? $org->metadata : null;
            });

            $config = $config ? json_decode($config, true) : [];

            $accessToken = $config['whatsapp']['access_token'] ?? null;
            $apiVersion = 'v18.0';
            $appId = $config['whatsapp']['app_id'] ?? null;
            $phoneNumberId = $config['whatsapp']['phone_number_id'] ?? null;
            $wabaId = $config['whatsapp']['waba_id'] ?? null;

            if (!$accessToken || !$phoneNumberId || !$wabaId) {
                throw new \Exception("Missing WhatsApp configuration for organization {$this->organizationId}");
            }

            $this->whatsappService = new WhatsappService(
                $accessToken, 
                $apiVersion, 
                $appId, 
                $phoneNumberId, 
                $wabaId, 
                $this->organizationId
            );
        } catch (\Exception $e) {
            Log::error('Failed to initialize WhatsApp service: ' . $e->getMessage(), [
                'organization_id' => $this->organizationId
            ]);
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('ProcessSingleCampaignLogJob failed permanently', [
            'campaign_log_id' => $this->campaignLog->id,
            'contact_id' => $this->campaignLog->contact_id ?? null,
            'campaign_id' => $this->campaignLog->campaign_id ?? null,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Mark the campaign log as failed if it's still ongoing
        try {
            $log = CampaignLog::find($this->campaignLog->id);
            if ($log && $log->status === 'ongoing') {
                $log->update([
                    'status' => 'failed',
                    'metadata' => json_encode([
                        'error' => 'Job failed permanently',
                        'message' => $exception->getMessage()
                    ])
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to update campaign log status on job failure: ' . $e->getMessage());
        }
    }
}