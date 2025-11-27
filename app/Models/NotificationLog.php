<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasFactory;
    public $timestamps = false;
    protected $fillable = [
        'user_id',
        'organization_id',
        'notification_type',
        'payload',
        'fcm_response',
        'delivered',
        'clicked',
    ];

    protected $casts = [
        'payload' => 'array',
        'fcm_response' => 'array',
        'delivered' => 'boolean',
        'clicked' => 'boolean',
    ];
}
