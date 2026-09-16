<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatbotStudioDraft extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $hidden = ['source', 'generation_state'];
    protected $casts = ['blueprint' => 'array', 'conversation' => 'array', 'result' => 'array',
        'sources' => 'array', 'errors' => 'array', 'generation_state' => 'array', 'token_usage' => 'array', 'version' => 'integer', 'organization_id' => 'integer', 'admin_id' => 'integer'];
}
