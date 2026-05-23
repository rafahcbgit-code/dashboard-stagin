<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactBotReenable extends Model
{
    protected $table = 'contact_bot_reenables';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'reenable_at' => 'datetime',
    ];
}