<?php

namespace Modules\MetaFlows\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetaFlowResponse extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'meta_flow_responses';

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
        'reply_sent_at' => 'datetime',
        'interactive_reply_sent_at' => 'datetime',
        'email_notification_sent_at' => 'datetime',
    ];

    // The flow this response belongs to
    public function metaFlow()
    {
        return $this->belongsTo(MetaFlow::class, 'meta_flow_id', 'id');
    }
}
