<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use App\Services\OrganizationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = true;

    /**
     * Boot the model.
     * Story 2.2: AC-2.2.2 - Cache invalidation when organization metadata is updated.
     */
    protected static function boot()
    {
        parent::boot();

        // Invalidate cache when organization is updated
        static::updated(function (Organization $organization) {
            // Check if metadata was changed
            if ($organization->isDirty('metadata') || $organization->wasChanged('metadata')) {
                OrganizationService::invalidateMetadataCache($organization->id);
            }
        });

        // Invalidate cache when organization is deleted
        static::deleted(function (Organization $organization) {
            OrganizationService::invalidateMetadataCache($organization->id);
        });
    }

    public function listAll($searchTerm, $userId = null)
    {
        $query = $this->with(['teams.user', 'owner.user', 'subscription.plan'])
            ->when($userId !== null, function ($query) use ($userId) {
                $query->whereHas('teams', function ($teamsQuery) use ($userId) {
                    $teamsQuery->where('user_id', $userId);
                });
            })
            ->where(function ($query) use ($searchTerm) {
                $query->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhereHas('owner.user', function ($ownerQuery) use ($searchTerm) {
                        $ownerQuery->where('first_name', 'like', '%' . $searchTerm . '%')
                        ->orWhere('last_name', 'like', '%' . $searchTerm . '%')
                                    ->orWhere('email', 'like', '%' . $searchTerm . '%');
                    });
            })
            ->withCount('teams')
            ->latest()
            ->paginate(50);

        return $query;
    }

    public function teams()
    {
        return $this->hasMany(Team::class, 'organization_id');
    }

    public function owner()
    {
        return $this->belongsTo(Team::class, 'id', 'organization_id')->where('role', 'owner');
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'id', 'organization_id');
    }

    public function labels()
    {
        return $this->hasMany(Label::class);
    }
}
