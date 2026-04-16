<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WooCommerceWebhookEventMapping extends Model
{
    use HasFactory;

    protected $table = 'woocommerce_webhook_event_mappings';
    protected $guarded = [];

    public function setting()
    {
        return $this->belongsTo(WooCommerceWebhookEventSetting::class, 'woocommerce_webhook_event_setting_id');
    }
}
