<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageRateLimit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'last_triggered_at' => 'datetime',
    ];

    public function triggerGroup()
    {
        return $this->belongsTo(TriggerGroup::class);
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeForContact($query, $contactId)
    {
        return $query->where('contact_id', $contactId);
    }

    public function scopeForTriggerGroup($query, $triggerGroupId)
    {
        return $query->where('trigger_group_id', $triggerGroupId);
    }
}