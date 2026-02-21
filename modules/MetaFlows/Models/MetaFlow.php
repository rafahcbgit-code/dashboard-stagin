<?php

namespace Modules\MetaFlows\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaFlow extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $table = 'meta_flows';

    protected $guarded = [];

    protected $casts = [
        'categories' => 'array',
    ];

    // A flow can receive many responses when contacts complete it
    public function responses()
    {
        return $this->hasMany(MetaFlowResponse::class, 'meta_flow_id', 'id');
    }

    // Return a paginated list for the given organization, optionally filtered by name
    public function listAll(int $organizationId, ?string $searchTerm)
    {
        return $this->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->when($searchTerm, function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%');
            })
            ->withCount('responses')
            ->latest()
            ->paginate(10);
    }
}
