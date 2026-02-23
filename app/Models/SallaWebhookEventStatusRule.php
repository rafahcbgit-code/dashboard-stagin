<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaWebhookEventStatusRule extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function setting()
    {
        return $this->belongsTo(SallaWebhookEventSetting::class, 'salla_webhook_event_setting_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function mappings()
    {
        return $this->hasMany(SallaWebhookEventStatusRuleMapping::class, 'salla_webhook_event_status_rule_id');
    }
}

