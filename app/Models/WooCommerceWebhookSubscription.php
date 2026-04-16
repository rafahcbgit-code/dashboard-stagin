<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Organization;
use App\Models\User;
use App\Models\WooCommerceIntegration;

class WooCommerceWebhookSubscription extends Model
{
    use HasFactory;

    protected $table = 'woocommerce_webhook_subscriptions';
    protected $fillable = [
        'organization_id',
        'user_id',
        'integration_id',
        'name',
        'topic',
        'delivery_url',
        'secret_hash',
        'status',
        'response_payload',
        'webhook_id',
        'is_active',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'is_active' => 'boolean',
    ];

    public function integration()
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'integration_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
