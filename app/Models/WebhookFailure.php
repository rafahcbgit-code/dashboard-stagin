<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebhookFailure - Records failed webhook processing jobs.
 *
 * Used for monitoring and debugging webhook job failures
 * after all retry attempts are exhausted.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $job_type
 * @property int|null $chat_id
 * @property int|null $contact_id
 * @property string|null $media_id
 * @property string|null $media_type
 * @property string $error_message
 * @property string|null $error_code
 * @property string|null $payload
 * @property int $attempts
 * @property \Carbon\Carbon $created_at
 */
class WebhookFailure extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'job_type',
        'chat_id',
        'contact_id',
        'media_id',
        'media_type',
        'error_message',
        'error_code',
        'payload',
        'attempts',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'chat_id' => 'integer',
        'contact_id' => 'integer',
        'attempts' => 'integer',
        'created_at' => 'datetime',
        'payload' => 'array',
    ];

    /**
     * Get the organization that owns the webhook failure.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope a query to only include failures for a given organization.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }
}
