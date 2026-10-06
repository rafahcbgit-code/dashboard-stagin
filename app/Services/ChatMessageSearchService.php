<?php

namespace App\Services;

use App\Models\Chat;
use App\Models\ChatArchive;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ChatMessageSearchService
{
    private static ?bool $indexAvailable = null;
    private static ?bool $revocationsAvailable = null;

    public function isReady(): bool
    {
        return Cache::remember('chat-search-index-ready-v2', 30, fn () =>
            Schema::hasTable('chat_search_index_state')
                && DB::table('chat_search_index_state')->where('version', 2)
                    ->whereNotNull('completed_at')->exists()
        );
    }

    public function index(Chat|ChatArchive $chat, ?\App\Models\ChatLog $chatLog = null): void
    {
        if (! (self::$indexAvailable ??= Schema::hasTable('chat_message_search'))) return;

        $metadata = json_decode($chat->metadata ?: '', true);
        $metadata = is_array($metadata) ? $metadata : [];
        $revokeId = data_get($metadata, 'revoke.original_message_id')
            ?? data_get($metadata, 'raw.revoke.original_message_id');
        if (($metadata['type'] ?? null) === 'revoke' && is_string($revokeId) && $revokeId !== '') {
            $this->revoke((int) $chat->organization_id, (int) $chat->contact_id, $revokeId);
            return;
        }

        if ($chat->deleted_at) {
            DB::table('chat_message_search')->where('chat_id', $chat->id)->delete();
            return;
        }

        if ($chat->wam_id && $this->isRevoked((int) $chat->organization_id, (int) $chat->contact_id, (string) $chat->wam_id)) {
            DB::table('chat_message_search')->where('chat_id', $chat->id)->delete();
            return;
        }

        $text = self::visibleText($chat->metadata);
        $terms = self::indexedTerms($text);
        if ($terms === '') {
            DB::table('chat_message_search')->where('chat_id', $chat->id)->delete();
            return;
        }

        $log = $chatLog ?? DB::table('chat_logs')->where('entity_type', 'chat')
            ->where('entity_id', $chat->id)->where('contact_id', $chat->contact_id)
            ->whereNull('deleted_at')->orderByDesc('id')->first(['id', 'created_at']);
        if (! $log) return;
        DB::table('chat_message_search')->upsert([[
                'chat_id' => $chat->id,
                'organization_id' => $chat->organization_id,
                'contact_id' => $chat->contact_id,
                'display_text' => $text,
                'search_text' => $terms,
                'created_at' => $chat->getRawOriginal('created_at'),
                'log_id' => $log?->id,
                'log_created_at' => $chatLog ? $chatLog->getRawOriginal('created_at') : $log->created_at,
                'message_type' => $chat->type,
            ]], ['chat_id'], ['organization_id', 'contact_id', 'display_text', 'search_text', 'created_at', 'log_id', 'log_created_at', 'message_type']);
    }

    public function attachLog(\App\Models\ChatLog $log): void
    {
        if ($log->entity_type !== 'chat' || ! (self::$indexAvailable ??= Schema::hasTable('chat_message_search'))) return;
        $chat = Chat::query()->whereKey($log->entity_id)->where('contact_id', $log->contact_id)->first();
        if ($chat) $this->index($chat, $log);
    }

    public function revoke(int $organizationId, int $contactId, string $wamId): void
    {
        if (! (self::$revocationsAvailable ??= Schema::hasTable('chat_search_revocations'))) return;

        DB::transaction(function () use ($organizationId, $contactId, $wamId) {
            DB::table('chat_search_revocations')->insertOrIgnore([
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'original_wam_id' => $wamId,
            ]);
            $ids = Chat::query()->where('organization_id', $organizationId)
                ->where('contact_id', $contactId)->where('wam_id', $wamId)->pluck('id');
            if ($ids->isEmpty()) {
                $ids = ChatArchive::query()->where('organization_id', $organizationId)
                    ->where('contact_id', $contactId)->where('wam_id', $wamId)->pluck('id');
            }
            if ($ids->isNotEmpty()) DB::table('chat_message_search')->whereIn('chat_id', $ids)->delete();
        });
    }

    private function isRevoked(int $organizationId, int $contactId, string $wamId): bool
    {
        return (self::$revocationsAvailable ??= Schema::hasTable('chat_search_revocations'))
            && DB::table('chat_search_revocations')->where('organization_id', $organizationId)
                ->where('contact_id', $contactId)->where('original_wam_id', $wamId)->exists();
    }

    /** Index a bounded backfill batch with one document write and one deletion. */
    public function indexBatch(iterable $messages): void
    {
        $messages = collect($messages);
        $revokeRows = [];
        foreach ($messages as $message) {
            $metadata = json_decode($message->metadata ?: '', true);
            if (! is_array($metadata) || ($metadata['type'] ?? null) !== 'revoke') continue;
            $wamId = data_get($metadata, 'revoke.original_message_id')
                ?? data_get($metadata, 'raw.revoke.original_message_id');
            if (is_string($wamId) && $wamId !== '') {
                $revokeRows[] = [
                    'organization_id' => $message->organization_id,
                    'contact_id' => $message->contact_id,
                    'original_wam_id' => $wamId,
                ];
            }
        }
        if ($revokeRows && (self::$revocationsAvailable ??= Schema::hasTable('chat_search_revocations'))) {
            DB::table('chat_search_revocations')->insertOrIgnore($revokeRows);
        }

        $wamIds = $messages->pluck('wam_id')->filter()->unique()->values()->all();
        $revoked = [];
        if ($wamIds && (self::$revocationsAvailable ??= Schema::hasTable('chat_search_revocations'))) {
            $rows = DB::table('chat_search_revocations')
                ->whereIn('organization_id', $messages->pluck('organization_id')->unique()->all())
                ->whereIn('contact_id', $messages->pluck('contact_id')->unique()->all())
                ->whereIn('original_wam_id', $wamIds)->get();
            foreach ($rows as $row) {
                $revoked[$row->organization_id . ':' . $row->contact_id . ':' . $row->original_wam_id] = true;
            }
        }

        $logs = DB::table('chat_logs')->where('entity_type', 'chat')
            ->whereIn('entity_id', $messages->pluck('id')->all())
            ->whereNull('deleted_at')->orderByDesc('id')
            ->get(['id', 'entity_id', 'contact_id', 'created_at']);
        $logByChat = [];
        foreach ($logs as $log) {
            $key = $log->contact_id . ':' . $log->entity_id;
            $logByChat[$key] ??= $log;
        }
        $documents = [];
        $remove = [];
        foreach ($messages as $message) {
            $key = $message->organization_id . ':' . $message->contact_id . ':' . $message->wam_id;
            $text = self::visibleText($message->metadata);
            $terms = self::indexedTerms($text);
            $log = $logByChat[$message->contact_id . ':' . $message->id] ?? null;
            if ($message->deleted_at || isset($revoked[$key]) || $terms === '' || ! $log) {
                $remove[] = $message->id;
                continue;
            }
            $documents[] = [
                'chat_id' => $message->id,
                'organization_id' => $message->organization_id,
                'contact_id' => $message->contact_id,
                'display_text' => $text,
                'search_text' => $terms,
                'created_at' => $message->getRawOriginal('created_at'),
                'log_id' => $log->id,
                'log_created_at' => $log->created_at,
                'message_type' => $message->type,
            ];
        }
        if ($remove) DB::table('chat_message_search')->whereIn('chat_id', $remove)->delete();
        if ($documents) DB::table('chat_message_search')->upsert(
            $documents, ['chat_id'], ['organization_id', 'contact_id', 'display_text', 'search_text', 'created_at', 'log_id', 'log_created_at', 'message_type']
        );
        foreach ($revokeRows as $row) {
            $this->revoke((int) $row['organization_id'], (int) $row['contact_id'], $row['original_wam_id']);
        }
    }

    public static function visibleText(?string $rawMetadata): string
    {
        $metadata = json_decode($rawMetadata ?: '', true);
        if (! is_array($metadata)) return '';

        if (data_get($metadata, 'revoked.is_revoked')) return '';

        $parts = [
            data_get($metadata, 'text.body'),
            data_get($metadata, 'text.footer'),
            data_get($metadata, 'header.text'),
            data_get($metadata, 'body.text'),
            data_get($metadata, 'footer.text'),
            data_get($metadata, 'carousel_message_body'),
            data_get($metadata, 'image.caption'),
            data_get($metadata, 'video.caption'),
            data_get($metadata, 'document.caption'),
            data_get($metadata, 'audio.caption'),
            data_get($metadata, 'media.caption'),
            data_get($metadata, 'button.text'),
            data_get($metadata, 'interactive.body.text'),
            data_get($metadata, 'interactive.button_reply.title'),
            data_get($metadata, 'interactive.list_reply.title'),
        ];

        foreach ((array) data_get($metadata, 'buttons', []) as $button) {
            $parts[] = is_array($button) ? ($button['text'] ?? null) : null;
        }
        foreach ((array) data_get($metadata, 'cards', []) as $card) {
            if (! is_array($card)) continue;
            $parts[] = $card['body_text'] ?? null;
            foreach ((array) ($card['buttons'] ?? []) as $button) {
                $parts[] = is_array($button) ? ($button['text'] ?? null) : null;
            }
        }
        return trim(implode(' ', array_filter($parts, fn ($part) => is_string($part) && trim($part) !== '')));
    }

    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي']);
        return mb_strtolower($text);
    }

    private static function tokens(string $text): array
    {
        return array_values(array_unique(preg_split(
            '/[^\p{L}\p{N}]+/u', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY
        ) ?: []));
    }

    private static function indexedTerms(string $text): string
    {
        return implode(' ', array_map(fn ($token) => 'zz' . $token, self::tokens($text)));
    }

    public function search(int $organizationId, int $contactId, string $phrase, ?string $before = null): array
    {
        $tokens = self::tokens($phrase);
        if (mb_strlen(self::normalize(trim($phrase))) < 3 || ! $tokens || count($tokens) > 8) {
            throw \Illuminate\Validation\ValidationException::withMessages(['q' => __('Enter between three characters and eight words')]);
        }

        $boolean = implode(' ', array_map(fn ($token) => '+zz' . $token . '*', $tokens));
        $query = DB::table('chat_message_search as s')
            ->where('s.organization_id', $organizationId)
            ->where('s.contact_id', $contactId)
            ->whereNotNull('s.log_id')
            ->whereNotNull('s.log_created_at')
            ->whereRaw('MATCH(s.search_text) AGAINST (? IN BOOLEAN MODE)', [$boolean])
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('chat_logs as l')
                    ->whereColumn('l.id', 's.log_id')->whereColumn('l.entity_id', 's.chat_id')
                    ->whereColumn('l.contact_id', 's.contact_id')->where('l.entity_type', 'chat')
                    ->whereNull('l.deleted_at');
            })
            ->where(function ($query) use ($organizationId, $contactId) {
                $query->whereExists(function ($query) use ($organizationId, $contactId) {
                    $query->selectRaw('1')->from('chats as c')->whereColumn('c.id', 's.chat_id')
                        ->where('c.organization_id', $organizationId)->where('c.contact_id', $contactId)
                        ->whereNull('c.deleted_at');
                })->orWhereExists(function ($query) use ($organizationId, $contactId) {
                    $query->selectRaw('1')->from('chats_archive as a')->whereColumn('a.id', 's.chat_id')
                        ->where('a.organization_id', $organizationId)->where('a.contact_id', $contactId)
                        ->whereNull('a.deleted_at');
                });
            });

        if ($before) {
            $decoded = base64_decode($before, true);
            if ($decoded === false || ! preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $matches)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['before' => __('Invalid search cursor')]);
            }
            $query->where(function ($query) use ($matches) {
                $query->where('s.log_created_at', '<', $matches[1])
                    ->orWhere(function ($query) use ($matches) {
                        $query->where('s.log_created_at', $matches[1])->where('s.log_id', '<', $matches[2]);
                    });
            });
        }

        $rows = $query->orderByDesc('s.log_created_at')->orderByDesc('s.log_id')->limit(21)
            ->get(['s.log_id', 's.log_created_at', 's.chat_id', 's.display_text', 's.created_at', 's.message_type']);
        $hasMore = $rows->count() > 20;
        $last = $rows->take(20)->last();
        $metadata = json_decode(Organization::query()->whereKey($organizationId)->value('metadata') ?: '{}', true);
        $timezone = $metadata['timezone'] ?? 'UTC';
        $results = $rows->take(20)->map(fn ($row) => [
            'log_id' => $row->log_id,
            'chat_id' => $row->chat_id,
            'text' => self::snippet($row->display_text, $tokens),
            'created_at' => Carbon::parse($row->created_at, 'UTC')->timezone($timezone)->format('Y-m-d H:i'),
            'type' => $row->message_type,
        ])->values()->all();

        return ['results' => $results, 'next_cursor' => $hasMore && $last
            ? base64_encode($last->log_created_at . '|' . $last->log_id) : null];
    }

    private static function snippet(string $text, array $tokens): string
    {
        $position = false;
        foreach ($tokens as $token) {
            $position = mb_stripos($text, $token);
            if ($position !== false) break;
        }
        $start = $position === false ? 0 : max(0, $position - 55);
        $excerpt = mb_substr($text, $start, 180);
        return ($start > 0 ? '…' : '') . $excerpt
            . (mb_strlen($text) > $start + mb_strlen($excerpt) ? '…' : '');
    }
}
