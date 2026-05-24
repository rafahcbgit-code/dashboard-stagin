<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappWebhookPayloadSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'webhook_type',
        'entry_count',
        'changes_count',
        'message_count',
        'status_count',
        'echo_count',
        'phone_number_id',
        'wam_ids',
        'payload_hash',
        'payload',
        'received_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'entry_count' => 'integer',
        'changes_count' => 'integer',
        'message_count' => 'integer',
        'status_count' => 'integer',
        'echo_count' => 'integer',
        'wam_ids' => 'array',
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
