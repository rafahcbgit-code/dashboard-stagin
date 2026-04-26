<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactImportBatch extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'headers' => 'array',
        'mapping' => 'array',
        'selected_group_ids' => 'array',
        'report' => 'array',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ContactImportRow::class, 'contact_import_batch_id');
    }
}

