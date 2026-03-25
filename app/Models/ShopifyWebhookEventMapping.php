<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyWebhookEventMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function setting()
    {
        return $this->belongsTo(ShopifyWebhookEventSetting::class, 'shopify_webhook_event_setting_id');
    }
}
