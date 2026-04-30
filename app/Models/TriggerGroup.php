<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TriggerGroup extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'keywords' => 'array',
        'metadata' => 'array',
        'is_system' => 'boolean',
        'is_enabled' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function flow()
    {
        return $this->belongsTo(\Modules\FlowBuilder\Models\Flow::class);
    }

    public function rateLimits()
    {
        return $this->hasMany(MessageRateLimit::class);
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

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function hasFlowLinked(): bool
    {
        return $this->flow_id !== null;
    }

    public function hasKeywords(): bool
    {
        return !empty($this->keywords);
    }

    public function isAiMode(): bool
    {
        return $this->match_mode === 'ai';
    }
}