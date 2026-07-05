<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\CampaignLog;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Campaign extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];
    public $timestamps = false;
    public ?object $precomputedCounts = null;

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getDeletedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getScheduledAtAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id', 'id');
    }

    public function contactGroup()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id', 'id');
    }

    public function campaignLogs()
    {
        return $this->hasMany(CampaignLog::class, 'campaign_id', 'id');
    }

    public function contactsCount()
    {
        return $this->campaignLogs->count();
    }

    public function contactGroupCount()
    {
        return $this->contactGroup ? (int) $this->contactGroup->contacts_count : 0;
    }

    public function sentCount()
    {
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->whereIn('status', ['accepted', 'sent', 'delivered', 'read']);
            })
            ->count();
    }

    public function deliveryCount()
    {
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->whereIn('status', ['delivered', 'read']);
            })
            ->count();
    }

    public function failedCount()
    {
        $failedToSendCount = $this->campaignLogs()->where('status', 'failed')->count();

        $chatFailedCount = $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->where('status', 'failed');
            })
            ->count();

        return $failedToSendCount + $chatFailedCount;
    }

    public function readCount()
    {
        return $this->campaignLogs()
            ->where('status', 'success')
            ->whereHas('chat', function ($query) {
                $query->where('status', 'read');
            })
            ->count();
    }

    public function getCounts()
    {
        if ($this->precomputedCounts !== null) {
            return $this->precomputedCounts;
        }

        return self::aggregateCountsForCampaignIds([$this->id])->get($this->id) ?? self::emptyCounts();
    }

    public function setPrecomputedCounts(?object $counts): void
    {
        $this->precomputedCounts = $counts ?? self::emptyCounts();
    }

    public static function aggregateCountsForCampaignIds(iterable $campaignIds): Collection
    {
        $ids = collect($campaignIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table('campaign_stats')
            ->whereIn('campaign_id', $ids->all())
            ->select([
                'campaign_id',
                'total_message_count',
                'total_sent_count',
                'total_delivered_count',
                'total_failed_count',
                'total_read_count',
                'processing_count',
                'queued_count',
            ])
            ->get()
            ->keyBy('campaign_id');
    }

    private static function emptyCounts(): object
    {
        return (object) [
            'total_message_count' => 0,
            'total_sent_count' => 0,
            'total_delivered_count' => 0,
            'total_failed_count' => 0,
            'total_read_count' => 0,
        ];
    }

    /**
     * Calculate total delivered messages across all campaigns for an organization.
     *
     * @param  int  $organizationId
     * @return int
     */
    public static function overallDeliveryCountForOrg(int $organizationId): int
    {
        return Cache::remember("campaigns:{$organizationId}:overall_delivery_count", 30, function () use ($organizationId) {
            return (int) DB::table('campaign_stats')
                ->where('organization_id', $organizationId)
                ->sum('total_delivered_count');
        });
    }

    /**
     * Accessor so that $campaign->overall_delivery_count returns org-wide value.
     */
    public function getOverallDeliveryCountAttribute(): int
    {
        return self::overallDeliveryCountForOrg($this->organization_id);
    }

    /**
     * Return the campaign messages limit configured for the organization (trial_limits).
     * Defaults to 250 if not set.
     */
    public static function campaignMessagesLimit(): int
    {
        $setting = Setting::where('key', 'trial_limits')->first();
        if (!$setting) {
            return 250;
        }

        $limits = json_decode($setting->value, true);
        if (isset($limits['campaign_messages'])) {
            return (int) $limits['campaign_messages'];
        }

        return 250;
    }
}
