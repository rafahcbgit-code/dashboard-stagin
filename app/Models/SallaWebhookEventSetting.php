<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaWebhookEventSetting extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
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
        return $this->hasMany(SallaWebhookEventMapping::class, 'salla_webhook_event_setting_id');
    }

    public function statusRules()
    {
        return $this->hasMany(SallaWebhookEventStatusRule::class, 'salla_webhook_event_setting_id');
    }
}
