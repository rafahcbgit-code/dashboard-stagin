<?php

namespace Modules\FlowBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class FlowInteractiveMessage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
    ];
}
