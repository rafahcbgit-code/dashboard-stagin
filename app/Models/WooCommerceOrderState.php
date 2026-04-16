<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WooCommerceOrderState extends Model
{
    use HasFactory;

    protected $table = 'woocommerce_order_states';

    protected $fillable = [
        'organization_id',
        'integration_id',
        'order_id',
        'last_status',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function integration()
    {
        return $this->belongsTo(WooCommerceIntegration::class, 'integration_id');
    }
}
