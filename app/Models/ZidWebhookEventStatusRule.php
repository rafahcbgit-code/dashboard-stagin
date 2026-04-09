<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidWebhookEventStatusRule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function setting()
    {
        return $this->belongsTo(ZidWebhookEventSetting::class, 'zid_webhook_event_setting_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function mappings()
    {
        return $this->hasMany(ZidWebhookEventStatusRuleMapping::class, 'zid_webhook_event_status_rule_id');
    }
}
