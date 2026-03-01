<?php

namespace App\Models;

use App\Models\Contact;
use App\Models\Organization;
use App\Models\OutboundMessageAttempt;
use App\Models\SallaWebhookDelivery;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundMessage extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'sent_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function delivery()
    {
        return $this->belongsTo(SallaWebhookDelivery::class, 'source_delivery_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function attemptLogs()
    {
        return $this->hasMany(OutboundMessageAttempt::class);
    }
}

