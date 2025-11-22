<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatNote extends Model 
{
    use HasFactory;
    use HasUuid;
    
    protected $guarded = [];
    public $timestamps = true;

    /**
     * Relationship: Note belongs to Contact
     */
    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Relationship: Note created by User
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relationship: Note deleted by User
     */
    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Convert created_at to organization timezone
     */
    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    /**
     * Convert updated_at to organization timezone
     */
    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    /**
     * Scope: Get notes for specific contact
     */
    public function scopeForContact($query, $contactId)
    {
        return $query->where('contact_id', $contactId)
            ->whereNull('deleted_at');
    }

    /**
     * Scope: Get notes created by user
     */
    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId)
            ->whereNull('deleted_at');
    }
}