<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundMessageAttempt extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];

    public function outboundMessage()
    {
        return $this->belongsTo(OutboundMessage::class);
    }
}

