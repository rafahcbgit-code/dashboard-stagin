<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiKnowledgeBase extends Model
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'ai_knowledge_base';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization($query, $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }
}