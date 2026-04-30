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
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'fcm_response' => 'array',
        'delivered' => 'boolean',
        'clicked' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function scopeUnread($query)
    {
        return $query->where('clicked', false);
    }

    public function scopeForUserOrg($query, int $userId, int $organizationId)
    {
        return $query->where('user_id', $userId)
            ->where('organization_id', $organizationId);
    }

    public function markAsRead(): void
    {
        $this->update(['clicked' => true, 'read_at' => now()]);
    }
}
