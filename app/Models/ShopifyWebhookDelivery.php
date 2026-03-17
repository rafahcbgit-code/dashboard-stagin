<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookDelivery extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
        'sent_chat_id' => 'integer',
        'scheduled_for' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function store()
    {
        return $this->belongsTo(ShopifyStore::class, 'shopify_store_id');
    }

    public function setPayloadAttribute($value): void
    {
        $this->attributes['payload'] = $this->serializeJson($value);
    }

    public function getPayloadAttribute($value): array
    {
        return $this->deserializeJson($value);
    }

    public function setHeadersAttribute($value): void
    {
        $this->attributes['headers'] = $this->serializeJson($value);
    }

    public function getHeadersAttribute($value): array
    {
        return $this->deserializeJson($value);
    }

    private function serializeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : json_encode($value);
    }

    private function deserializeJson($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        return json_decode($value, true) ?? [];
    }

    public function markProcessed(): void
    {
        $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }
}
