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
        'run_uuid',
        'node_id',
        'variable_name',
        'question_text',
        'input_type',
        'answer_value',
        'status',
        'answered_at',
        'submission_data',
    ];

    protected $casts = [
        'submission_data' => 'array',
        'answered_at' => 'datetime',
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
