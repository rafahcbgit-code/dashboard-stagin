<?php

namespace App\Http\Resources;

use App\Helpers\DateTimeHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Convert updated_at to the organization's timezone and format it
        $updatedAt = DateTimeHelper::convertToOrganizationTimezone($this->updated_at);
        $data['updated_at'] = DateTimeHelper::formatDate($updatedAt);
        $metadata = is_array($this->metadata)
            ? $this->metadata
            : (json_decode($this->metadata ?: '{}', true) ?: []);
        $body = collect($metadata['components'] ?? [])->first(
            fn ($component) => strtoupper((string) ($component['type'] ?? '')) === 'BODY'
        );
        $data['preview_text'] = (string) ($body['text'] ?? $metadata['carousel_message_body'] ?? '');
        $data['needs_action'] = in_array($this->status, ['REJECTED', 'PAUSED', 'DISABLED'], true)
            || ($this->category === 'MARKETING' && ! $this->has_unsubscribe_action);
        $data['has_unsubscribe_action'] = (bool) $this->has_unsubscribe_action;
        $data['last_rejection_reason'] = $this->last_rejection_reason;

        return $data;
    }
}
