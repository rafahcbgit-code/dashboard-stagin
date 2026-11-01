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
            'chat_created_at',
            'chat_metadata',
            'status',
            'reply_to_message_id',
            'chat_type',
            'wam_id',
            'contact_phone',
            'contact_last_name',
            'contact_first_name',
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
                'c.created_at as chat_created_at',
                'c.metadata as chat_metadata',
                'c.status',
                'c.reply_to_message_id',
                'c.type as chat_type',
                'c.wam_id',
                'ct.phone as contact_phone',
                'ct.last_name as contact_last_name',
                'ct.first_name as contact_first_name',
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
