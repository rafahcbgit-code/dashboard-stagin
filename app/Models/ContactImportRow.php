<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportRow extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'raw_data' => 'array',
        'mapped_data' => 'array',
        'normalized_data' => 'array',
        'errors' => 'array',
        'is_removed' => 'boolean',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ContactImportBatch::class, 'contact_import_batch_id');
    }
}

