<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use App\Services\CampaignStatsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignLog extends Model {
    use HasFactory;

    protected $guarded = [];
    public $timestamps = true;

    protected static function booted(): void
    {
        static::created(function (CampaignLog $log): void {
            CampaignStatsService::recordSnapshotsChanged([], [
                CampaignStatsService::snapshot($log),
            ]);
        });

        static::updated(function (CampaignLog $log): void {
            if (! $log->wasChanged(['campaign_id', 'status', 'chat_id'])) {
                return;
            }

            CampaignStatsService::recordSnapshotsChanged([
                [
                    'id' => (int) $log->id,
                    'campaign_id' => (int) $log->getOriginal('campaign_id'),
                    'status' => $log->getOriginal('status'),
                    'chat_id' => $log->getOriginal('chat_id') ? (int) $log->getOriginal('chat_id') : null,
                ],
            ], [
                CampaignStatsService::snapshot($log),
            ]);
        });

        static::deleted(function (CampaignLog $log): void {
            CampaignStatsService::recordSnapshotsChanged([
                CampaignStatsService::snapshot($log),
            ], []);
        });
    }

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function getUpdatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function campaign(){
        return $this->belongsTo(Campaign::class, 'campaign_id', 'id');
    }

    public function contact(){
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function chat(){
        return $this->belongsTo(Chat::class, 'chat_id', 'id');
    }

    public function retries(){
        return $this->hasMany(CampaignLogRetry::class);
    }
}
