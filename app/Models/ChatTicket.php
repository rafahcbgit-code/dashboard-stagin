<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatTicket extends Model {
    use HasFactory;
    
    protected $guarded = [];
    public $timestamps = false;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function user(){
        return $this->belongsTo(User::class, 'assigned_to', 'id')
            ->whereNull('users.deleted_at')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('teams')
                    ->whereColumn('teams.user_id', 'users.id')
                    ->whereNull('teams.deleted_at');
            });
    }
}
