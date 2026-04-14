<?php

namespace App\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WooCommerceIntegration extends Model
{
    use HasFactory;

    protected $table = 'woocommerce_integrations';
    protected $fillable = [
        'organization_id',
        'user_id',
        'consumer_key',
        'consumer_secret',
        'domain_link',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
