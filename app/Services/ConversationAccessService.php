<?php

namespace App\Services;

use App\Models\ChatTicket;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;

class ConversationAccessService
{
    /**
     * Determine whether a user may access the conversation represented by a contact.
     *
     * This is intentionally stricter than organization membership alone because the
     * result is used to authorize conversation-scoped real-time channels.
     */
    public function canAccess(User $user, Contact $contact): bool
    {
        $organizationId = (int) $contact->organization_id;

        if ($organizationId <= 0) {
            return false;
        }

        $team = Team::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $team) {
            return false;
        }

        if (in_array($team->role, ['owner', 'manager'], true)) {
            return true;
        }

        if ($team->role !== 'agent') {
            return false;
        }

        $ticketSettings = $this->ticketSettings($organizationId);

        if (! $ticketSettings['active'] || $ticketSettings['allow_agents_to_view_all_chats']) {
            return true;
        }

        return ChatTicket::query()
            ->where('contact_id', $contact->id)
            ->where('organization_id', $organizationId)
            ->where('assigned_to', $user->id)
            ->exists();
    }

    /**
     * Return the identity data Pusher exposes to other members of a presence
     * channel. No customer or message data is included.
     */
    public function presenceData(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => trim((string) $user->full_name) ?: 'An agent',
        ];
    }

    private function ticketSettings(int $organizationId): array
    {
        $metadata = Organization::query()
            ->whereKey($organizationId)
            ->value('metadata');

        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $tickets = is_array($metadata) && is_array($metadata['tickets'] ?? null)
            ? $metadata['tickets']
            : [];

        return [
            'active' => (bool) ($tickets['active'] ?? false),
            'allow_agents_to_view_all_chats' => (bool) ($tickets['allow_agents_to_view_all_chats'] ?? false),
        ];
    }
}
