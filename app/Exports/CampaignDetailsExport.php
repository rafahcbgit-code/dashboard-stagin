<?php

namespace App\Exports;

use App\Models\Campaign;
use App\Models\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CampaignDetailsExport implements FromQuery, WithChunkReading, WithHeadings, WithMapping
{
    private string $uuid;
    private int $organizationId;
    private string $timezone = 'UTC';
    private ?Campaign $campaign = null;

    public function __construct(?string $uuid)
    {
        $this->uuid = (string) $uuid;
        $this->organizationId = (int) session()->get('current_organization');
        $this->timezone = $this->resolveOrganizationTimezone();
    }

    /**
    * @return \Illuminate\Database\Query\Builder
    */
    public function query()
    {
        $campaign = $this->campaign();

        if (! $campaign) {
            Log::warning('CampaignDetailsExport campaign not found', [
                'uuid' => $this->uuid,
                'organization_id' => $this->organizationId,
            ]);

            return DB::query()
                ->from('campaign_logs')
                ->whereRaw('1 = 0')
                ->select('campaign_logs.id');
        }

        $firstStatusLog = DB::table('chat_status_logs')
            ->select('metadata')
            ->whereColumn('chat_status_logs.chat_id', 'campaign_logs.chat_id')
            ->orderBy('id')
            ->limit(1);

        return DB::table('campaign_logs')
            ->leftJoin('contacts', 'campaign_logs.contact_id', '=', 'contacts.id')
            ->leftJoin('chats as chat', 'campaign_logs.chat_id', '=', 'chat.id')
            ->where('campaign_logs.campaign_id', $campaign->id)
            ->select([
                'campaign_logs.id',
                'campaign_logs.status as campaign_log_status',
                'campaign_logs.updated_at',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.phone',
                'chat.status as chat_status',
            ])
            ->selectSub($firstStatusLog, 'status_log_metadata')
            ->orderBy('campaign_logs.id');
    }

    public function map($row): array
    {
        $campaign = $this->campaign();

        return [
            'campaign_name' => $campaign?->name ?? '',
            'template_name' => $campaign?->template?->name ?? '',
            'first_name' => $this->decodeUnicodeBytes($row->first_name ?? ''),
            'last_name' => $this->decodeUnicodeBytes($row->last_name ?? ''),
            'phone' => $this->formatPhoneNumber($row->phone ?? ''),
            'updated_at' => $this->formatDateTime($row->updated_at ?? null),
            'status' => $row->campaign_log_status === 'success' ? ($row->chat_status ?? '') : $row->campaign_log_status,
            'info' => $this->extractErrorMessage($row->status_log_metadata ?? null),
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function headings(): array
    {
        // Define your headers here
        return [
            'Campaign Name',
            'Template',
            'First Name',
            'Last Name',
            'Phone Number',
            'Last Updated',
            'Status',
            'Info'
        ];
    }

    private function campaign(): ?Campaign
    {
        if ($this->campaign !== null) {
            return $this->campaign;
        }

        $this->campaign = Campaign::query()
            ->with('template:id,name')
            ->where('uuid', $this->uuid)
            ->where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->select(['id', 'uuid', 'organization_id', 'template_id', 'name'])
            ->first();

        return $this->campaign;
    }

    private function extractErrorMessage(?string $metadata): string
    {
        if (! $metadata) {
            return '';
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? (string) data_get($decoded, 'errors.0.message', '') : '';
    }

    private function formatPhoneNumber(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        $contact = new Contact([
            'phone' => $phone,
            'organization_id' => $this->organizationId,
        ]);

        return $contact->formatted_phone_number;
    }

    private function decodeUnicodeBytes(?string $value): string
    {
        return preg_replace_callback('/\\\\x([0-9A-F]{2})/i', function ($matches) {
            return chr(hexdec($matches[1]));
        }, (string) $value);
    }

    private function formatDateTime(?string $dateTime): string
    {
        if (! $dateTime) {
            return '';
        }

        try {
            return Carbon::parse($dateTime)->setTimezone($this->timezone)->toDateTimeString();
        } catch (\Throwable $e) {
            return Carbon::parse($dateTime)->setTimezone('UTC')->toDateTimeString();
        }
    }

    private function resolveOrganizationTimezone(): string
    {
        if ($this->organizationId <= 0) {
            return 'UTC';
        }

        $metadata = DB::table('organizations')
            ->where('id', $this->organizationId)
            ->value('metadata');

        $decoded = is_string($metadata) ? json_decode($metadata, true) : null;

        $timezone = is_array($decoded) && isset($decoded['timezone']) ? (string) $decoded['timezone'] : 'UTC';

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : 'UTC';
    }
}
