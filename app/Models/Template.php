<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Template extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    protected $casts = [
        'internal_metadata' => 'array',
        'has_unsubscribe_action' => 'boolean',
        'last_status_change_at' => 'datetime',
        'disabled_at' => 'datetime',
        'last_used_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'remote_missing_at' => 'datetime',
    ];

    public $timestamps = false;

    public function statusEvents()
    {
        return $this->hasMany(TemplateStatusEvent::class)->latest('occurred_at');
    }
}
