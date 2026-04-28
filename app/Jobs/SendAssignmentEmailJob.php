<?php

namespace App\Jobs;

use App\Services\OrganizationMailConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAssignmentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    private int $organizationId;
    private int $agentId;
    private int $contactId;

    public function __construct(int $organizationId, int $agentId, int $contactId)
    {
        $this->organizationId = $organizationId;
        $this->agentId = $agentId;
        $this->contactId = $contactId;
    }

    public function handle(OrganizationMailConfigService $mailService): void
    {
        $sent = $mailService->sendAssignmentEmail(
            $this->organizationId,
            $this->agentId,
            $this->contactId
        );

        if (!$sent) {
            Log::info('SendAssignmentEmailJob: Email not sent (disabled or misconfigured)', [
                'org_id' => $this->organizationId,
                'agent_id' => $this->agentId,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendAssignmentEmailJob failed', [
            'org_id' => $this->organizationId,
            'agent_id' => $this->agentId,
            'error' => $exception->getMessage(),
        ]);
    }
}