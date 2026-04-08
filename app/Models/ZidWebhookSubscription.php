<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidWebhookSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'platform',
        'store_id',
        'event_name',
        'webhook_id',
        'original_id',
        'target_url',
        'conditions_json',
        'status',
    ];

    protected $casts = [
        'conditions_json' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
