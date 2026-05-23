<?php

namespace App\Services;

use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Jobs\ReEnableBotForContactJob;
use App\Jobs\SendAssignmentEmailJob;
use App\Services\BotSettingsService;
use App\Services\RateLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgentRoutingService
{
    /**
     * Assign a conversation to an agent based on the routing target.
     *
     * @param int $organizationId
     * @param int $contactId
     * @param string $targetType  'auto_assignment' or 'specific_agent'
     * @param int|null $targetAgentId  Required when target_type is 'specific_agent'
     * @return ChatTicket|null  The assigned ticket, or null on failure
     */
    public function assignToAgent(int $organizationId, int $contactId, string $targetType, ?int $targetAgentId = null): ?ChatTicket
    {
        try {
            // Reset fallback counter — escalation served its purpose
            app(RateLimitService::class)->resetFallbackCounter($organizationId, $contactId);

            // Disable bot for this contact on escalation
            $this->disableBotOnEscalation($organizationId, $contactId);

            $existingTicket = ChatTicket::where('contact_id', $contactId)
                ->where('organization_id', $organizationId)
                ->where('status', 'open')
                ->whereNotNull('assigned_to')
                ->first();

            if ($existingTicket) {
                // For specific_agent escalation, reassign to the target agent
                if ($targetType === 'specific_agent' && $targetAgentId && $existingTicket->assigned_to !== $targetAgentId) {
                    $previousAgent = $existingTicket->assigned_to;
                    $existingTicket->assigned_to = $targetAgentId;
                    $existingTicket->updated_at = now();
                    $existingTicket->save();
                    $this->sendNotification($contactId, $targetAgentId, $organizationId);

                    Log::info('AgentRoutingService: Reassigned to specific agent', [
                        'contact_id' => $contactId,
                        'previous_agent' => $previousAgent,
                        'new_agent' => $targetAgentId,
                    ]);
                } else {
                    Log::info('AgentRoutingService: Ticket already open and assigned', [
                        'contact_id' => $contactId,
                        'assigned_to' => $existingTicket->assigned_to,
                    ]);
                }

                return $existingTicket;
            }

            if ($targetType === 'specific_agent') {
                return $this->assignToSpecificAgent($organizationId, $contactId, $targetAgentId);
            }

            // auto_assignment: use org's configured mode (round-robin or least-loaded)
            return $this->assignByOrgMode($organizationId, $contactId);
        } catch (\Throwable $e) {
            Log::error('AgentRoutingService: Assignment failed', [
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'target_type' => $targetType,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Assign directly to a specific agent.
     */
    private function assignToSpecificAgent(int $organizationId, int $contactId, ?int $agentId): ?ChatTicket
    {
        if (!$agentId) {
            Log::warning('AgentRoutingService: specific_agent target but no agent_id provided');
            return null;
        }

        // Verify the agent belongs to this organization
        $agentValid = DB::table('teams')
            ->where('organization_id', $organizationId)
            ->where('user_id', $agentId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();

        if (!$agentValid) {
            Log::warning('AgentRoutingService: Agent not found in organization', [
                'organization_id' => $organizationId,
                'agent_id' => $agentId,
            ]);
            // Fallback to org auto-assignment mode
            return $this->assignByOrgMode($organizationId, $contactId);
        }

        $ticket = $this->createOrReopenTicket($organizationId, $contactId, $agentId);
        $this->sendNotification($contactId, $agentId, $organizationId);

        Log::info('AgentRoutingService: Assigned to specific agent', [
            'contact_id' => $contactId,
            'agent_id' => $agentId,
        ]);

        return $ticket;
    }

    /**
     * Assign using the organization's configured auto-assignment mode.
     * Checks org metadata for tickets.assigned_to_specific_agents (round-robin)
     * or tickets.auto_assignment (least-loaded).
     */
    private function assignByOrgMode(int $organizationId, int $contactId): ?ChatTicket
    {
        $org = Organization::find($organizationId);
        if (!$org) {
            return null;
        }

        $metadata = $org->metadata ? json_decode($org->metadata) : (object) [];
        $isRobinhood = !empty($metadata->tickets->assigned_to_specific_agents ?? false);
        $isAutoAssign = !empty($metadata->tickets->active ?? false) && !empty($metadata->tickets->auto_assignment ?? false);

        if ($isRobinhood) {
            return $this->assignRoundRobin($organizationId, $contactId);
        }

        if ($isAutoAssign) {
            return $this->assignLeastLoaded($organizationId, $contactId);
        }

        // No auto-assignment configured — create ticket but leave unassigned
        Log::info('AgentRoutingService: No auto-assignment configured, creating unassigned ticket', [
            'organization_id' => $organizationId,
        ]);

        return $this->createOrReopenTicket($organizationId, $contactId, null);
    }

    /**
     * Round-robin assignment among selected agents in assigned_agents table.
     */
    private function assignRoundRobin(int $organizationId, int $contactId): ?ChatTicket
    {
        $agents = DB::table('assigned_agents')
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->pluck('user_id')
            ->toArray();

        if (empty($agents)) {
            Log::warning('AgentRoutingService: No agents in round-robin pool');
            return $this->createOrReopenTicket($organizationId, $contactId, null);
        }

        // Find the last ticket assigned among these agents
        $lastTicket = ChatTicket::whereIn('assigned_to', $agents)
            ->where('organization_id', $organizationId)
            ->latest()
            ->first();

        if ($lastTicket) {
            $lastIndex = array_search($lastTicket->assigned_to, $agents);
            $nextIndex = ($lastIndex === false) ? 0 : ($lastIndex + 1) % count($agents);
        } else {
            $nextIndex = 0;
        }

        $agentId = $agents[$nextIndex];

        $ticket = $this->createOrReopenTicket($organizationId, $contactId, $agentId);
        $this->sendNotification($contactId, $agentId, $organizationId);

        Log::info('AgentRoutingService: Round-robin assignment', [
            'contact_id' => $contactId,
            'assigned_to' => $agentId,
        ]);

        return $ticket;
    }

    /**
     * Least-loaded assignment — assign to the agent with fewest open tickets.
     */
    private function assignLeastLoaded(int $organizationId, int $contactId): ?ChatTicket
    {
        $agent = DB::table('teams')
            ->select('teams.user_id')
            ->where('teams.organization_id', $organizationId)
            ->where('teams.status', 'active')
            ->whereNull('teams.deleted_at')
            ->leftJoin('chat_tickets', function ($join) use ($organizationId) {
                $join->on('teams.user_id', '=', 'chat_tickets.assigned_to')
                    ->where('chat_tickets.organization_id', '=', $organizationId)
                    ->where('chat_tickets.status', '=', 'open');
            })
            ->groupBy('teams.user_id')
            ->orderByRaw('COUNT(chat_tickets.id) ASC')
            ->first();

        if (!$agent) {
            Log::warning('AgentRoutingService: No active agents found for least-loaded assignment');
            return $this->createOrReopenTicket($organizationId, $contactId, null);
        }

        $agentId = $agent->user_id;

        $ticket = $this->createOrReopenTicket($organizationId, $contactId, $agentId);
        $this->sendNotification($contactId, $agentId, $organizationId);

        Log::info('AgentRoutingService: Least-loaded assignment', [
            'contact_id' => $contactId,
            'assigned_to' => $agentId,
        ]);

        return $ticket;
    }

    /**
     * Create a new ticket or reopen an existing one.
     */
    private function createOrReopenTicket(int $organizationId, int $contactId, ?int $agentId): ChatTicket
    {
        $ticket = ChatTicket::where('contact_id', $contactId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($ticket) {
            // Reopen if closed
            if ($ticket->status === 'closed') {
                $ticket->status = 'open';
            }
            $ticket->assigned_to = $agentId;
            $ticket->updated_at = now();
            $ticket->save();

            $this->logTicketEvent($contactId, 'Conversation was moved from closed to open');
        } else {
            $ticket = new ChatTicket();
            $ticket->organization_id = $organizationId;
            $ticket->contact_id = $contactId;
            $ticket->status = 'open';
            $ticket->assigned_to = $agentId;
            $ticket->created_at = now();
            $ticket->updated_at = now();
            $ticket->save();

            $this->logTicketEvent($contactId, 'Conversation was opened');
        }

        return $ticket;
    }

    /**
     * Send push notification to the assigned agent.
     */
    private function sendNotification(int $contactId, ?int $agentId, int $organizationId): void
    {
        if (!$agentId) {
            return;
        }

        // FCM push notification
        try {
            $notificationService = new ApiNotificationService();
            $notificationService->sendAssignmentNotification(
                $contactId,
                $agentId,
                0,
                $organizationId
            );
        } catch (\Throwable $e) {
            Log::error('AgentRoutingService: Push notification failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Email notification (queued, non-blocking)
        try {
            SendAssignmentEmailJob::dispatch($organizationId, $agentId, $contactId);
        } catch (\Throwable $e) {
            Log::error('AgentRoutingService: Email job dispatch failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log a ticket event.
     */
    private function logTicketEvent(int $contactId, string $description): void
    {
        try {
            $ticketLogId = DB::table('chat_ticket_logs')->insertGetId([
                'contact_id' => $contactId,
                'description' => $description,
                'created_at' => now(),
            ]);

            DB::table('chat_logs')->insert([
                'contact_id' => $contactId,
                'entity_type' => 'ticket',
                'entity_id' => $ticketLogId,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AgentRoutingService: Failed to log ticket event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function disableBotOnEscalation(int $organizationId, int $contactId): void
    {
        try {
            $contact = Contact::find($contactId);
            if (!$contact) {
                return;
            }

            $autoReenableMinutes = app(BotSettingsService::class)->getAutoReenableBotMinutes($organizationId);

            $contact->bot_enable = false;

            if ($autoReenableMinutes > 0) {
                $contact->bot_auto_reenable_at = now()->addMinutes($autoReenableMinutes);

                ReEnableBotForContactJob::dispatch($contactId, $organizationId)
                    ->delay($autoReenableMinutes * 60)
                    ->onQueue('default');

                Log::info('AgentRoutingService: Bot auto-disabled on escalation, auto-reenable scheduled', [
                    'contact_id' => $contactId,
                    'auto_reenable_minutes' => $autoReenableMinutes,
                ]);
            } else {
                $contact->bot_auto_reenable_at = null;

                Log::info('AgentRoutingService: Bot auto-disabled on escalation, no auto-reenable', [
                    'contact_id' => $contactId,
                ]);
            }

            $contact->updated_at = now();
            $contact->save();
        } catch (\Throwable $e) {
            Log::error('AgentRoutingService: Failed to disable bot on escalation', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}