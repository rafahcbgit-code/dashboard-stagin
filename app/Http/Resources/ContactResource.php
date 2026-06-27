<?php

namespace App\Http\Resources;

use App\Models\Chat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        $data['unread_messages'] = $this->unread_messages_count ?? $this->chats()
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->where('is_read', 0)
            ->count();

        if (method_exists($this->resource, 'shouldMaskPhoneForCurrentViewer')
            && $this->resource->shouldMaskPhoneForCurrentViewer()) {
            $maskedPhone = $this->resource->getMaskedPhoneForCurrentViewer();
            $data['phone'] = $maskedPhone;
            $data['formatted_phone_number'] = $maskedPhone;
        }

        $lastInboundAt = $this->lastInboundChatAt();
        if ($lastInboundAt) {
            $data['last_inbound_chat_at'] = $lastInboundAt->toIso8601String();
            $data['reply_window_expires_at'] = $lastInboundAt->copy()->addHours(24)->toIso8601String();
            $data['can_send_session_message'] = $lastInboundAt->greaterThan(now('UTC')->subHours(24));
        } else {
            $data['last_inbound_chat_at'] = null;
            $data['reply_window_expires_at'] = null;
            $data['can_send_session_message'] = false;
        }
        
        return $data;
    }

    private function lastInboundChatAt(): ?Carbon
    {
        $lastInboundChat = $this->whenLoaded('lastInboundChat');

        if (!$lastInboundChat instanceof \Illuminate\Http\Resources\MissingValue && $lastInboundChat) {
            return Carbon::parse($lastInboundChat->getRawOriginal('created_at'), 'UTC');
        }

        $createdAt = Chat::where('contact_id', $this->id)
            ->where('type', 'inbound')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->value('created_at');

        return $createdAt ? Carbon::parse($createdAt, 'UTC') : null;
    }
}
