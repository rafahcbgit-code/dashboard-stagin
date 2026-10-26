<?php

namespace App\Exports;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OrganizationChatsExport
{
    public function __construct(
        private readonly Organization $organization,
        private readonly string $type,
        private readonly ?string $dateFrom = null,
        private readonly ?string $dateTo = null,
    ) {
    }

    public function headers(): array
    {
        return [
            'chat_id', 'organization_id', 'contact_id', 'user_id', 'chat_type',
            'wam_id', 'reply_to_message_id', 'status', 'media_id', 'is_read',
            'chat_metadata', 'chat_created_at', 'contact_first_name',
            'contact_last_name', 'contact_phone', 'contact_email', 'contact_address',
            'contact_metadata', 'contact_is_subscribed',
        ];
    }

    public function query(): Builder
    {
        $query = DB::table('chats as c')
            ->leftJoin('contacts as ct', function ($join) {
                $join->on('ct.id', '=', 'c.contact_id')
                    ->on('ct.organization_id', '=', 'c.organization_id');
            })
            ->where('c.organization_id', $this->organization->id)
            ->select([
                'c.id as chat_id', 'c.organization_id', 'c.contact_id', 'c.user_id',
                'c.type as chat_type', 'c.wam_id', 'c.reply_to_message_id',
                'c.status', 'c.media_id', 'c.is_read', 'c.metadata as chat_metadata',
                'c.created_at as chat_created_at', 'ct.first_name as contact_first_name',
                'ct.last_name as contact_last_name', 'ct.phone as contact_phone',
                'ct.email as contact_email', 'ct.address as contact_address',
                'ct.metadata as contact_metadata', 'ct.is_subscribed as contact_is_subscribed',
            ])
            ->orderBy('c.contact_id')
            ->orderBy('c.created_at')
            ->orderBy('c.id');

        if ($this->type === 'ad_campaigns') {
            $query->whereRaw('JSON_VALID(c.metadata) = 1')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(c.metadata, '$.referral.source_type')) = ?", ['ad']);
        }

        $timezone = $this->organization->effectiveTimezone();

        if ($this->dateFrom) {
            $query->where('c.created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $this->dateFrom, $timezone)->startOfDay()->utc());
        }

        if ($this->dateTo) {
            $query->where('c.created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $this->dateTo, $timezone)->endOfDay()->utc());
        }

        return $query;
    }

    public function filename(): string
    {
        return sprintf(
            'organization-%d-chats-%s-%s-%s.csv',
            $this->organization->id,
            $this->type,
            $this->dateFrom ?: 'all',
            $this->dateTo ?: 'all'
        );
    }
}
