<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactBotReenable extends Model
{
    protected $table = 'contact_bot_reenables';
     protected $primaryKey = 'contact_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'contact_id',
        'organization_id',
        'reenable_at',
    ];

    protected $casts = [
        'contact_id' => 'integer',
        'organization_id' => 'integer',
        'reenable_at' => 'datetime',
    ];
}