<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactChatState extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'contact_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * Atomically retain only the latest chat for a contact. The chat ID breaks
     * ties when more than one message is stored in the same second.
     */
    public static function syncLatest(
        int $contactId,
        int $organizationId,
        $createdAt,
        int $chatId,
        ?string $chatType
    ): int {
        $state = [
            'contact_id' => $contactId,
            'organization_id' => $organizationId,
            'latest_chat_id' => $chatId,
            'latest_chat_type' => $chatType,
            'latest_chat_created_at' => $createdAt,
        ];

        if (static::query()->insertOrIgnore($state) > 0) {
            return 1;
        }

        return static::query()
            ->whereKey($contactId)
            ->where(function ($query) use ($createdAt, $chatId) {
                $query->whereNull('latest_chat_created_at')
                    ->orWhere('latest_chat_created_at', '<', $createdAt)
                    ->orWhere(function ($sameTimestampQuery) use ($createdAt, $chatId) {
                        $sameTimestampQuery->where('latest_chat_created_at', '=', $createdAt)
                            ->where('latest_chat_id', '<', $chatId);
                    });
            })
            ->update($state);
    }
}
