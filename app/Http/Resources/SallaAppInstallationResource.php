<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SallaAppInstallationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'organization' => $this->organization ? [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ] : null,
            'salla_installation_id' => $this->salla_installation_id,
            'app_name' => $this->app_name,
            'app_type' => $this->app_type,
            'store_type' => $this->store_type,
            'scopes_count' => $this->scopes_count,
            'installation_date' => optional($this->installation_date)->toDateTimeString(),
            'uninstallation_date' => optional($this->uninstallation_date)->toDateTimeString(),
            'uninstalled' => (bool) ($this->uninstalled ?? false),
            'webhook_created_at' => optional($this->webhook_created_at)->toDateTimeString(),
            'last_seen_at' => optional($this->last_seen_at)->toDateTimeString(),
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'pending_uninstall_count' => (int) ($this->pending_uninstall_count ?? 0),
        ];
    }
}

