<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceAddon extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'valid_until' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
