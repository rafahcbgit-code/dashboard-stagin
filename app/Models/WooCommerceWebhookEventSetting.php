<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WooCommerceWebhookEventSetting extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $table = 'woocommerce_webhook_event_settings';

    protected $casts = [
        'enabled' => 'boolean',
        'delay_hours' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function mappings()
    {
        return $this->hasMany(WooCommerceWebhookEventMapping::class, 'woocommerce_webhook_event_setting_id');
    }
}
