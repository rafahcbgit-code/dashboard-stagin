<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopifyStore extends Model
{
    use HasFactory;
    protected $fillable = ['shop_url', 'access_token', 'status', 'organization_id'];
    protected $casts = [
        'organization_id' => 'integer',
    ];
}
