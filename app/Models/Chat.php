<?php

namespace App\Models;

use App\Http\Traits\HasUuid;
use Illuminate\Support\Carbon;
use App\Helpers\DateTimeHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Chat extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($chat) {
            // Ensure created_at is always in UTC before saving
            if (!isset($chat->created_at) || !$chat->created_at) {
                $chat->created_at = now()->setTimezone('UTC')->toDateTimeString();
            } else {
                // If created_at is set (from webhook), ensure it's in UTC
                $chat->created_at = Carbon::parse($chat->created_at)
                    ->setTimezone('UTC')
                    ->toDateTimeString();
            }
        });

        static::created(function ($chat) {
        $contact = $chat->contact;
        if ($contact) {
                // Store in UTC (don't convert, it's already UTC from creating event)
                $contact->latest_chat_created_at = $chat->getRawOriginal('created_at'); // Get raw UTC value
                $contact->save();
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
