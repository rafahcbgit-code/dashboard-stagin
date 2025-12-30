<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chat extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::created(function ($chat) {
        if ($chat->contact_id && isset($chat->attributes['created_at'])) {
            // Direct DB update - bypasses all Eloquent magic
            \DB::table('contacts')
                ->where('id', $chat->contact_id)
                ->update([
                    'latest_chat_created_at' => $chat->attributes['created_at']
                ]);
        }
        });

        /*static::updated(function ($chat) {
            $contact = $chat->contact;
            if ($contact) {
                $latestChat = Chat::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->first();
                $contact->latest_chat_created_at = $latestChat ? $latestChat->created_at : null;
                $contact->save();
            }
        });*/
    }
    
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
