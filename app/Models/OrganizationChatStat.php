<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OrganizationChatStat extends Model
{
    protected $fillable = [
        'organization_id',
        'active_chats_count',
        'conversations_count',
    ];

    public $timestamps = true;

    public static function incrementForNewChat(int $organizationId, bool $newConversation): void
    {
        static::query()->upsert([
            [
                'organization_id' => $organizationId,
                'active_chats_count' => 1,
                'conversations_count' => $newConversation ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['organization_id'], [
            'active_chats_count' => DB::raw('active_chats_count + 1'),
            'conversations_count' => DB::raw('conversations_count + '.($newConversation ? 1 : 0)),
            'updated_at' => now(),
        ]);
    }

    public static function decrementActiveChats(int $organizationId, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        static::query()
            ->where('organization_id', $organizationId)
            ->update([
                'active_chats_count' => DB::raw('GREATEST(active_chats_count - '.$count.', 0)'),
                'updated_at' => now(),
            ]);
    }
}
