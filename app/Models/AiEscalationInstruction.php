<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiEscalationInstruction extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'ai_escalation_instructions';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function targetAgent()
    {
        return $this->belongsTo(User::class, 'target_agent_id');
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrderedByPriority($query)
    {
        return $query->orderBy('priority', 'asc');
    }
}