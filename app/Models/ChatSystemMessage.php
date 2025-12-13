<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatSystemMessage extends Model
{
    use HasFactory;
    
    public $timestamps = false;
    protected $fillable = [
        'contact_id',
        'type',
        'sent_at',
    ];

    protected $casts = [
    'sent_at' => 'datetime',
    ];
}
