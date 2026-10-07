<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateStatusEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }
}
