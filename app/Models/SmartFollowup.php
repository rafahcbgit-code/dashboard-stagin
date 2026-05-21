<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Modules\FlowBuilder\Models\Flow;

class SmartFollowup extends Model
{
    use HasUuid;

    public const TYPE_REJOIN_REMINDER = 'rejoin_reminder';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'reminder_minutes' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeRejoinReminder($query)
    {
        return $query->where('type', self::TYPE_REJOIN_REMINDER);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}
