<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionUsageEvent extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'quantity' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
