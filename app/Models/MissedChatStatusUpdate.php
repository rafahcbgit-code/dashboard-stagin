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
        'retry_count',
        'next_retry_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    // Constants for states
    const STATE_NOT_PROCESSED = 'not_processed';
    const STATE_PROCESSING = 'processing';
    const STATE_PROCESSED = 'processed';
    const STATE_FAILED = 'failed';

    const MAX_RETRIES = 10; // Maximum retry attempts


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
        return $query->where('updated_at', '<', now()->subMinutes($minutes));
    }

    public function scopeReadyForRetry($query)
    {
        return $query->where('state', self::STATE_NOT_PROCESSED)
            ->where(function($q) {
                $q->whereNull('next_retry_at')
                  ->orWhere('next_retry_at', '<=', now());
            });
    }

    public function scopeFailed($query)
    {
        return $query->where('state', self::STATE_FAILED);
    }

    /**
     * Calculate next retry time using exponential backoff
     * 1st retry: 2 minutes
     * 2nd retry: 5 minutes
     * 3rd retry: 10 minutes
     * 4th retry: 20 minutes
     * 5th+ retry: 30 minutes
     */
    public static function calculateNextRetryTime($retryCount)
    {
        $backoffMinutes = match(true) {
            $retryCount === 0 => 2,
            $retryCount === 1 => 5,
            $retryCount === 2 => 10,
            $retryCount === 3 => 20,
            default => 30
        };

        return now()->addMinutes($backoffMinutes);
    }

    public function hasExceededMaxRetries()
    {
        return $this->retry_count >= self::MAX_RETRIES;
    }
}
