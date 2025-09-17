<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MissedChatStatusUpdate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'wam_id',
        'organization_id',
        'payload',
        'status',
        'state',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    // Constants for states
    const STATE_NOT_PROCESSED = 'not_processed';
    const STATE_PROCESSING = 'processing';
    const STATE_PROCESSED = 'processed';


    public function scopeNotProcessed($query)
    {
        return $query->where('state', self::STATE_NOT_PROCESSED);
    }

    public function scopeProcessing($query)
    {
        return $query->where('state', self::STATE_PROCESSING);
    }

    public function scopeProcessed($query)
    {
        return $query->where('state', self::STATE_PROCESSED);
    }

    public function scopeOlderThan($query, $minutes)
    {
        return $query->where('created_at', '<', now()->subMinutes($minutes));
    }
}
