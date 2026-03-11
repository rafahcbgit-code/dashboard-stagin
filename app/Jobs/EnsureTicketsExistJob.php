<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ChatTicket;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnsureTicketsExistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $organizationId;

    /**
     * Create a new job instance.
     */
    public function __construct($organizationId = null)
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get all organizations with ticketing enabled
            $organizations = Organization::whereNotNull('metadata')->get();

            foreach ($organizations as $organization) {
                // Skip if specific org requested and doesn't match
                if ($this->organizationId && $organization->id != $this->organizationId) {
                    continue;
                }

                $settings = json_decode($organization->metadata);

                // Only process if tickets are active
                if (isset($settings->tickets) && $settings->tickets->active === true) {
                    $this->ensureTicketsForOrganization($organization->id);
                }
            }

            Log::info('EnsureTicketsExistJob completed successfully');
        } catch (\Exception $e) {
            Log::error('EnsureTicketsExistJob failed: ' . $e->getMessage());
        }
    }

    /**
     * Ensure tickets exist for all contacts with chats in an organization
     */
    private function ensureTicketsForOrganization($organizationId): void
    {
        $contact = new Contact();
        $allContactsForTickets = $contact->contactsWithChats($organizationId, null);

        $ticketsCreated = 0;

        foreach ($allContactsForTickets as $contactItem) {
            $ticket = ChatTicket::firstOrCreate(
                ['contact_id' => $contactItem->id],
                [
                    'assigned_to' => null,
                    'status' => 'open',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if (!$ticket->created_at) {
                $ticket->created_at = now();
                $ticket->save();
            }

            if ($ticket->wasRecentlyCreated) {
                $ticketsCreated++;
            }
        }

        if ($ticketsCreated > 0) {
            Log::info("Created {$ticketsCreated} tickets for organization {$organizationId}");
        }
    }
}
