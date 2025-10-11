<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
class Subscription extends Model {
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    protected $appends = ['next_billing_date'];
    public $timestamps = true;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getValidUntilAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getStartDateAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id', 'id');
    }

   public function getNextBillingDateAttribute()
{
    if (!$this->plan) {
        return null;
    }

    if ($this->valid_until) {
        return Carbon::parse($this->valid_until)->toDateTimeString();
    }

    if ($this->start_date) {
        $start = Carbon::parse($this->start_date);

        $next = match ($this->plan->period) {
            'monthly' => $start->copy()->addMonth(),
            'yearly'  => $start->copy()->addYear(),
        };

        return $next ? $next->toDateTimeString() : null;
    }

    return null;
}
}