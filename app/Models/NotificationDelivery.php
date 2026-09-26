<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'destination' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'next_attempt_at' => 'datetime',
        'queued_at' => 'datetime',
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
