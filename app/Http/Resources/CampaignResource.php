<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $counts = $this->getCounts();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'template' => $this->template,
            'status' => $this->status,
            'contacts_count' => $counts->total_message_count ?? 0,
            'delivery_count' => $counts->total_delivered_count ?? 0,
            'read_count' => $counts->total_read_count ?? 0,
            'contact_group_count' => $this->contact_group_id == 0
                ? \Illuminate\Support\Facades\Cache::remember("org_{$this->organization_id}_contact_count", 300, function () {
                    return \App\Models\Contact::where('organization_id', $this->organization_id)->whereNull('deleted_at')->count();
                })
                : $this->contactGroupCount(),
        ];
    }
}
