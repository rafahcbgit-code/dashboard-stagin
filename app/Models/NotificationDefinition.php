<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'revision' => 'integer',
        'webhook_url' => 'encrypted',
        'webhook_secret' => 'encrypted',
    ];
}
