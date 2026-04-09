<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZidWebhookEventSetting extends Model
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
        return $this->hasMany(ZidWebhookEventMapping::class, 'zid_webhook_event_setting_id');
    }
}
