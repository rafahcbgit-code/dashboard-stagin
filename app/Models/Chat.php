<?php

namespace App\Models;

use App\Helpers\DateTimeHelper;
use App\Http\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Models\OrganizationChatStat;
use App\Services\CampaignStatsService;

class Chat extends Model {
    use HasFactory;
    use HasUuid;

    private const STATUS_ORDER = [
        'accepted' => 1,
        'sent' => 1,
        'delivered' => 2,
        'read' => 3,
        'failed' => 4,
    ];

    protected $fillable = [
        'contact_id',
        'user_id',
        'type',
        'wam_id',
        'organization_id',
        'status',
        'media_id',
        'metadata',
        'is_read',
        'created_at'
            ];
    protected $guarded = [];
    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::created(function ($chat) {
            $createdAt = $chat->getRawOriginal('created_at') ?? $chat->attributes['created_at'] ?? now();

            $newConversation = Contact::whereKey($chat->contact_id)
                ->whereNull('latest_chat_created_at')
                ->update(['latest_chat_created_at' => $createdAt]) > 0;

            if (!$newConversation) {
                Contact::whereKey($chat->contact_id)
                ->where(function ($query) use ($createdAt) {
                    $query->where('latest_chat_created_at', '<', $createdAt);
                })
                ->update(['latest_chat_created_at' => $createdAt]);
            }

            try {
                OrganizationChatStat::incrementForNewChat((int) $chat->organization_id, $newConversation);
            } catch (\Throwable $e) {
                report($e);
            }

            static::rememberStatusRank((int) $chat->id, (string) $chat->status);
        });

        /*static::updated(function ($chat) {
            $contact = $chat->contact;
            if ($contact) {
                $latestChat = Chat::where('contact_id', $contact->id)->orderBy('created_at', 'desc')->first();
                $contact->latest_chat_created_at = $latestChat ? $latestChat->created_at : null;
                $contact->save();
            }
        });*/
    }

    public function getCreatedAtAttribute($value)
    {
        return DateTimeHelper::convertToOrganizationTimezone($value)->toDateTimeString();
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id', 'id');
    }

    public function media()
    {
        return $this->belongsTo(ChatMedia::class, 'media_id', 'id');
    }

    public function logs()
    {
        return $this->hasMany(ChatStatusLog::class, 'chat_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function updateStatusIfProgressed(int $chatId, string $status): int
    {
        $rank = self::STATUS_ORDER[$status] ?? null;

        if ($rank !== null && static::cachedStatusRank($chatId) >= $rank) {
            return 0;
        }

        $oldStatus = static::whereKey($chatId)->value('status');

        $query = static::whereKey($chatId);

        if ($rank === null) {
            $query->where('status', '<>', $status);
        } else {
            $blockedStatuses = array_keys(array_filter(
                self::STATUS_ORDER,
                static fn (int $currentRank): bool => $currentRank >= $rank
            ));

            $query->where(function ($query) use ($blockedStatuses) {
                $query->whereNull('status')
                    ->orWhereNotIn('status', $blockedStatuses);
            });
        }

        $updated = $query->update(['status' => $status]);

        if ($updated > 0) {
            static::rememberStatusRank($chatId, $status);
            CampaignStatsService::recordChatStatusChanged($chatId, $oldStatus, $status);
        }

        return $updated;
    }

    public static function markInboundAsReadForContact(int $contactId, ?int $organizationId = null, int $chunkSize = 100): int
    {
        $updated = 0;

        do {
            $query = static::query()
                ->where('contact_id', $contactId)
                ->where('type', 'inbound')
                ->where('is_read', 0)
                ->whereNull('deleted_at');

            if ($organizationId !== null) {
                $query->where('organization_id', $organizationId);
            }

            $ids = $query->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $affected = static::whereKey($ids->all())->update(['is_read' => 1]);
            $updated += $affected;

            if ($affected > 0 && $organizationId !== null) {
                Cache::forget("org_{$organizationId}_global_unread");
            }

            if ($affected === 0) {
                break;
            }
        } while ($ids->count() === $chunkSize);

        return $updated;
    }

    private static function cachedStatusRank(int $chatId): int
    {
        if (!static::usesFastStatusCache()) {
            return 0;
        }

        return (int) Cache::get(static::statusCacheKey($chatId), 0);
    }

    private static function rememberStatusRank(int $chatId, string $status): void
    {
        $rank = self::STATUS_ORDER[$status] ?? null;

        if ($rank === null || !static::usesFastStatusCache()) {
            return;
        }

        Cache::put(static::statusCacheKey($chatId), $rank, now()->addDays(2));
    }

    private static function statusCacheKey(int $chatId): string
    {
        return "chat_status_rank:{$chatId}";
    }

    private static function usesFastStatusCache(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached', 'apc'], true);
    }
}
