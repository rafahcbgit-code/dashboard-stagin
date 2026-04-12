<?php

namespace App\Models;

use App\Models\ZidWebhookEventStatusRule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidWebhookEventSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
        'send_delay_hours' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function mappings()
    {
        return $this->hasMany(ZidWebhookEventMapping::class, 'zid_webhook_event_setting_id');
    }

    public function statusRules()
    {
        return $this->hasMany(ZidWebhookEventStatusRule::class, 'zid_webhook_event_setting_id');
    }
}
