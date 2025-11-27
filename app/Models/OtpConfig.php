<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpConfig extends Model
{
    use HasFactory;
    protected $fillable = ['template_name', 'uuid', 'contact_phone', 'calendly_url'];
      public function listAll($searchTerm)
    {
        return $this
                    ->latest()
                    ->paginate(10);
    }
}
