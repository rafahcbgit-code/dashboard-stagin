<?php

namespace Modules\FlowBuilder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\FlowBuilder\Services\FlowExecutionService;

class ProcessDelayedFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $contactId;
    protected $flowId;
    protected $currentStep;
    protected $organizationId;

    /**
     * Create a new job instance.
     */
    public function __construct($contactId, $flowId, $currentStep, $organizationId)
    {
        $this->contactId = $contactId;
        $this->flowId = $flowId;
        $this->currentStep = $currentStep;
        $this->organizationId = $organizationId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            // Log::info("Processing delayed flow continuation for contact {$this->contactId} at step {$this->currentStep}");
            
            // Continue the flow from the current step
            $flowExecutionService = new FlowExecutionService($this->organizationId);
            $flowExecutionService->continueDelayedFlow($this->contactId, $this->flowId, $this->currentStep);
            
        } catch (\Exception $e) {
            Log::error("Error processing delayed flow: " . $e->getMessage());
        }
    }
} 