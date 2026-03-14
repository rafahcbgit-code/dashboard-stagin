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

    /**
     * Returns true if the given organization currently has an active trial subscription.
     */
    public static function isActiveTrialForOrg(int $organizationId): bool
    {
        // Use the most recent subscription record for this organization.
        $subscription = self::where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->first();

        if (! $subscription) {
            return false;
        }

        // Treat as trial for display purposes if status is trial.
        if ($subscription->status === 'trial') {
            return true;
        }

        // Some subscriptions may remain 'active' while still in a trial window.
        // If a valid_until date exists and is in the future, consider this a trial for display.
        if ($subscription->valid_until) {
            return Carbon::parse($subscription->valid_until)->isFuture();
        }

        return false;
    }

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