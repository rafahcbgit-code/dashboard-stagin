<?php

namespace App\Services\Templates;

use App\Models\Organization;

class TemplateConnectionService
{
    public function forOrganization(Organization $organization): array
    {
        $metadata = json_decode($organization->metadata ?: '{}', true) ?: [];
        $whatsapp = is_array($metadata['whatsapp'] ?? null) ? $metadata['whatsapp'] : [];
        $status = strtoupper((string) ($whatsapp['number_status'] ?? ''));
        $hasIdentifiers = ! empty($whatsapp['phone_number_id']) && ! empty($whatsapp['waba_id']);

        return [
            'configured' => $hasIdentifiers,
            'connected' => $hasIdentifiers && $status === 'CONNECTED',
            'status' => $status !== '' ? $status : 'UNKNOWN',
            'phone_number' => $whatsapp['display_phone_number'] ?? $whatsapp['phone_number'] ?? null,
            'settings_url' => '/settings/whatsapp',
        ];
    }

    public function assertConnected(Organization $organization): void
    {
        abort_unless(
            $this->forOrganization($organization)['connected'],
            409,
            __('Reconnect your WhatsApp number before managing message templates.')
        );
    }
}
