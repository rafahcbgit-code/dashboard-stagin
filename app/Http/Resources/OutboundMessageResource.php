<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutboundMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_event' => $this->source_event,
            'automation_key' => $this->automation_key,
            'recipient_e164' => $this->recipient_e164,
            'contact_id' => $this->contact_id,
            'template_id' => $this->template_id,
            'template_name' => $this->template_name,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'sent_chat_id' => $this->sent_chat_id,
            'sent_at' => optional($this->sent_at)->toDateTimeString(),
            'last_error_message' => $this->last_error_message,
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}

