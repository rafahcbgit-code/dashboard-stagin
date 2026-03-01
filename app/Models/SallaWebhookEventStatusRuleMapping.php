<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaWebhookEventStatusRuleMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function rule()
    {
        return $this->belongsTo(SallaWebhookEventStatusRule::class, 'salla_webhook_event_status_rule_id');
    }
}

