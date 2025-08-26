<?php

namespace App\Models;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Label extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'color',
        'organization_id'
    ];

    public $timestamps = true;

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
