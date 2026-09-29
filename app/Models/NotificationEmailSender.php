<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationEmailSender extends Model
{
    protected $fillable = ['email', 'host', 'port', 'encryption', 'username', 'password'];

    protected $hidden = ['password'];

    protected $casts = [
        'port' => 'integer',
        'password' => 'encrypted',
    ];
}
