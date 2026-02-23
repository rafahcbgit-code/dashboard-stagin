<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SallaWebhookEventMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function setting()
    {
        return $this->belongsTo(SallaWebhookEventSetting::class, 'salla_webhook_event_setting_id');
    }
}

