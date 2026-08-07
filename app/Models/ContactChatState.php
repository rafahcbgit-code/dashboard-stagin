<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Backfills a chunk using one conditional upsert. Unlike a regular upsert,
     * an older backfill row can never overwrite a message received while the
     * command is running.
     *
     * @param array<int, array{contact_id: int, organization_id: int, latest_chat_id: int, latest_chat_type: ?string, latest_chat_created_at: mixed}> $states
     */
    public static function syncMany(array $states): int
    {
        if ($states === []) {
            return 0;
        }

        $bindings = [];
        $placeholders = [];

        foreach ($states as $state) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $bindings[] = $state['contact_id'];
            $bindings[] = $state['organization_id'];
            $bindings[] = $state['latest_chat_id'];
            $bindings[] = $state['latest_chat_type'];
            $bindings[] = $state['latest_chat_created_at'];
        }

        $incomingIsLatest = '(`latest_chat_created_at` IS NULL'
            .' OR `latest_chat_id` IS NULL'
            .' OR VALUES(`latest_chat_created_at`) > `latest_chat_created_at`'
            .' OR (VALUES(`latest_chat_created_at`) = `latest_chat_created_at`'
            .' AND VALUES(`latest_chat_id`) > `latest_chat_id`))';

        return DB::affectingStatement(
            'INSERT INTO `contact_chat_states`'
            .' (`contact_id`, `organization_id`, `latest_chat_id`, `latest_chat_type`, `latest_chat_created_at`)'
            .' VALUES '.implode(', ', $placeholders)
            .' ON DUPLICATE KEY UPDATE'
            .' `organization_id` = IF('.$incomingIsLatest.', VALUES(`organization_id`), `organization_id`),'
            .' `latest_chat_type` = IF('.$incomingIsLatest.', VALUES(`latest_chat_type`), `latest_chat_type`),'
            .' `latest_chat_id` = IF('.$incomingIsLatest.', VALUES(`latest_chat_id`), `latest_chat_id`),'
            .' `latest_chat_created_at` = IF('.$incomingIsLatest.', VALUES(`latest_chat_created_at`), `latest_chat_created_at`)',
            $bindings
        );
    }
}
