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
        'last_status_change_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public $timestamps = false;
}
