<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatStatusLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_at',
        'metadata',
        'chat_id'
    ];
    protected $guarded = [];
    public $timestamps = false;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }
}
