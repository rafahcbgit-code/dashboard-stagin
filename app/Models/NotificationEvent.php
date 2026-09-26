<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'rendered' => 'array',
        'is_test' => 'boolean',
        'next_attempt_at' => 'datetime',
        'queued_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
