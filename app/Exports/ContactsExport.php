<?php

namespace App\Exports;

use App\Models\Contact;
use App\Models\ContactField;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ContactsExport implements FromQuery, WithChunkReading, WithMapping, WithHeadings
{
    private int $organizationId;
    private array $fieldNames = [];

    public function __construct()
    {
        $this->organizationId = (int) session()->get('current_organization');
        $this->fieldNames = ContactField::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->pluck('name')
            ->toArray();
    }

    public function query()
    {
        try {
            return Contact::query()
                ->with(['contactGroups:id,name'])
                ->where('organization_id', $this->organizationId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'organization_id',
                    'first_name',
                    'last_name',
                    'phone',
                    'email',
                    'address',
                    'metadata',
                    'is_subscribed',
                ]);
        } catch (\Exception $e) {
            Log::error('ContactsExport query error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    public function map($contact): array
    {
        try {
            $address = json_decode($contact->address, true);
            $metadata = json_decode($contact->metadata, true) ?: [];

            $row = [
                $contact->first_name,
                $contact->last_name,
                $contact->formatted_phone_number,
                ((int) $contact->is_subscribed === 1) ? 'Subscribed' : 'Not subscribed',
                $contact->email,
                $contact->contactGroups->pluck('name')->implode('|'),
                $address['street'] ?? null,
                $address['city'] ?? null,
                $address['state'] ?? null,
                $address['zip'] ?? null,
                $address['country'] ?? null,
            ];

            foreach ($this->fieldNames as $fieldName) {
                $row[] = $metadata[$fieldName] ?? null;
            }

            return $row;
        } catch (\Exception $e) {
            Log::error('ContactsExport map error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function headings(): array
    {
        try {
            // Define your headers here
            $headers = [
                'First name',
                'Last name',
                'Phone',
                'Subscription',
                'Email',
                'Group name',
                'Street',
                'City',
                'State',
                'Zip',
                'Country'
            ];

            // Add dynamic field names to headers
            foreach ($this->fieldNames as $fieldName) {
                $headers[] = $fieldName;
            }

            return $headers;
        } catch (\Exception $e) {
            Log::error('ContactsExport headings error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }
}
