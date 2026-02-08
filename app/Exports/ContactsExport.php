<?php

namespace App\Exports;

use App\Models\Contact;
use App\Models\ContactField;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ContactsExport implements FromCollection, WithHeadings
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        try {
            $contacts = Contact::with('contactGroups')
                ->where('organization_id', session()->get('current_organization'))
                ->whereNull('deleted_at')
                ->get();

            // Get dynamic fields from the contact_fields table
            $dynamicFields = ContactField::where('organization_id', session()->get('current_organization'))
                ->whereNull('deleted_at')
                ->get();

            // Extract field names from the dynamic fields
            $fieldNames = $dynamicFields->pluck('name')->toArray();

            // Modify the collection to include formatted phone numbers and group names
            return $contacts->map(function ($contact) use ($fieldNames) {
                $address = json_decode($contact->address, true);
                $row = [
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'phone' => $contact->formatted_phone_number, // Assuming formatted_phone_number is defined
                    'subscription' => ((int) $contact->is_subscribed == 1) ? 'Subscribed' : 'Not subscribed',
                    'email' => $contact->email,
                    'group_name' => $contact->contactGroups->pluck('name')->implode('|'),
                    'street' => $address['street'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => $address['state'] ?? null,
                    'zip' => $address['zip'] ?? null,
                    'country' => $address['country'] ?? null,
                ];

                foreach ($fieldNames as $fieldName) {
                    $metadata = json_decode($contact->metadata, true);

                    if (isset($metadata[$fieldName])) {
                        $row[$fieldName] = $metadata[$fieldName];
                    } else {
                        $row[$fieldName] = null;
                    }
                }

                return $row;
            });
        } catch (\Exception $e) {
            Log::error('ContactsExport collection error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    public function headings(): array
    {
        try {
            $dynamicFields = ContactField::where('organization_id', session()->get('current_organization'))
                ->whereNull('deleted_at')
                ->get();

            $fieldNames = $dynamicFields->pluck('name')->toArray();

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
            foreach ($fieldNames as $fieldName) {
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
