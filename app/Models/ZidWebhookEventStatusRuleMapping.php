<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidWebhookEventStatusRuleMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function rule()
    {
        return $this->belongsTo(ZidWebhookEventStatusRule::class, 'zid_webhook_event_status_rule_id');
    }
}
