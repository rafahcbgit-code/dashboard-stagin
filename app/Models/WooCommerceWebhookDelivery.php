<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WooCommerceWebhookDelivery extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $table = "woocommerce_webhook_deliveries";

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'processed_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function integration()
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'integration_id');
    }

    public function subscription()
    {
        return $this->belongsTo(WooCommerceWebhookSubscription::class, 'subscription_id');
    }
}
