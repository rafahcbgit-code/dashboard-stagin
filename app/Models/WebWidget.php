<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'logo',
        'whatsapp_phone',
        'header_text',
        'header_subtext',
        'widget_text',
        'button_text',
        'widget_type',
        'input_placeholder',
        'button_color',
        'header_color',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
