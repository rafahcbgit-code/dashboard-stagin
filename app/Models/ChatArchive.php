<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatArchive extends Model
{
    use HasFactory;

    protected $table = 'chats_archive';

    protected $fillable = [
        'id',
        'uuid',
        'organization_id',
        'contact_id',
        'user_id',
        'wam_id',
        'type',
        'metadata',
        'media_id',
        'status',
        'is_read',
        'deleted_by',
        'deleted_at',
        'created_at',
        'archived_at',
    ];

    protected $guarded = [];

    public $timestamps = false;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function media()
    {
        return $this->belongsTo(ChatMedia::class, 'media_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(ChatStatusLog::class, 'chat_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
