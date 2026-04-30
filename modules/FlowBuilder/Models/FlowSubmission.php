<?php

namespace Modules\FlowBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowSubmission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'flow_id',
        'contact_id',
        'submission_data',
    ];

    protected $casts = [
        'submission_data' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(\App\Models\Organization::class);
    }

    public function flow()
    {
        return $this->belongsTo(Flow::class);
    }

    public function contact()
    {
        return $this->belongsTo(\App\Models\Contact::class);
    }
}