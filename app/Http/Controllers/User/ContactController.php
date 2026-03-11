<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Team;
use App\Models\Organization;
use App\Exports\ContactsExport;
use App\Http\Requests\StoreContact;
use App\Http\Resources\ContactResource;
use App\Imports\ContactsImport;
use App\Services\ContactFieldService;
use App\Services\ContactService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Excel;
use Validator;

class ContactController extends BaseController
{
    private function contactService()
    {
        return new ContactService(session()->get('current_organization'));
    }

    private function getCurrentOrganizationId()
    {
        return session()->get('current_organization');
    }

    public function index(Request $request, $uuid = null)
    {
        $organizationId = $this->getCurrentOrganizationId();
        $contactModel = new Contact;

        if ($uuid === 'export') {
            $format = $request->query('format', 'xlsx');
            return $this->exportContacts($format);
        } else if ($uuid === 'template') {
            return $this->downloadTemplate();
        } else {
            $searchTerm = $request->query('search');
            $uuid = $request->query('id') ? $request->query('id') : $uuid;
            $editContact = $request->query('edit') === 'true' ? true : false;

            $contacts = $contactModel->getAllContacts($organizationId, $searchTerm);
            $rowCount = $contactModel->countContacts($organizationId);
            $contactGroups = $contactModel->getAllContactGroups($organizationId);
            $contact = Contact::with('contactGroups')->where('uuid', $uuid)->where('deleted_at', null)->first();
            $contactFields = ContactField::where('organization_id', $organizationId)->where('deleted_at', null)->get();
            $team = Team::where('organization_id', $organizationId)->where('user_id', auth()->user()->id)->first();

            return Inertia::render('User/Contact/Index', [
                'title' => __('Contacts'),
                'rows' => ContactResource::collection($contacts),
                'rowCount' => $rowCount,
                'contact' => $contact,
                'fields' => $contactFields,
                'contactGroups' => $contactGroups,
                'filters' => request()->all(),
                'locationSettings' => $this->getLocationSettings(),
                'editContact' => $editContact,
                'role' => $team->role
            ]);
        }
    }

    public function exportContacts($format = 'xlsx')
    {
        try {
            Log::error('Export', [
                'message' => "start exporting"
            ]);

            if ($format === 'csv') {
                return $this->exportContactsAsCsv();
            }

            return Excel::download(new ContactsExport, 'contacts.xlsx');
        } catch (\Exception $e) {
            Log::error('Export Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Export failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportContactsAsCsv()
    {
        try {
            $organizationId = $this->getCurrentOrganizationId();
            $fieldNames = ContactField::where('organization_id', $organizationId)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->toArray();

            // Define headers
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

            return response()->streamDownload(function () use ($organizationId, $headers, $fieldNames) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, $headers);

                Contact::with(['contactGroups:id,name'])
                    ->where('organization_id', $organizationId)
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
                        'is_subscribed'
                    ])
                    ->orderBy('id')
                    ->chunkById(1000, function ($contacts) use ($handle, $fieldNames) {
                        foreach ($contacts as $contact) {
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

                            foreach ($fieldNames as $fieldName) {
                                $row[] = $metadata[$fieldName] ?? null;
                            }

                            fputcsv($handle, $row);
                        }
                    });

                fclose($handle);
            }, 'contacts.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        } catch (\Exception $e) {
            Log::error('Export CSV Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Export failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadTemplate()
    {
        $organizationId = $this->getCurrentOrganizationId();

        // Get custom contact fields for this organization
        $customFields = ContactField::where('organization_id', $organizationId)
            ->where('deleted_at', null)
            ->pluck('name')
            ->toArray();

        // Define the standard columns in the order specified
        $standardColumns = [
            'first_name',
            'last_name',
            'phone',
            'email',
            'group_name',
            'street',
            'city',
            'state',
            'zip',
            'country'
        ];

        // Add custom fields after the standard columns
        $allColumns = array_merge($standardColumns, $customFields);

        // Create sample data
        $sampleData = [
            [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+1234567890',
                'email' => 'john.doe@example.com',
                'group_name' => 'Customers',
                'street' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
                'country' => 'United States'
            ]
        ];

        // Add custom field values to sample data
        foreach ($customFields as $field) {
            $sampleData[0][strtolower(str_replace(' ', '_', $field))] = 'Sample ' . $field;
        }

        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'contacts_template_');

        // Open file for writing
        $handle = fopen($tempFile, 'w');

        // Write headers
        fputcsv($handle, $allColumns);

        // Write sample data
        foreach ($sampleData as $row) {
            $csvRow = [];
            foreach ($allColumns as $column) {
                $csvRow[] = $row[$column] ?? '';
            }
            fputcsv($handle, $csvRow);
        }

        fclose($handle);

        // Return the file as download
        return response()->download($tempFile, 'contacts_template.csv')->deleteFileAfterSend(true);
    }
    public function import(Request $request)
    {

        try {
            set_time_limit(0);
            ini_set('memory_limit', '2048M'); // 2GB RAM
            ini_set('max_execution_time', 0);

            // Disable MySQL strict mode temporarily for better performance
            DB::statement("SET SESSION sql_mode=''");
            // Initialize the import process
            $import = new ContactsImport();

            // Handle file import
            Excel::import($import, $request->file);

            // Get the count of successful imports
            $successfulImports = $import->getsuccessfulImports();
            $totalImports = $import->getTotalImportsCount();
            $failedImports = $totalImports - $successfulImports;

            // Prepare status message based on the import outcome
            if ($successfulImports === 0) {
                $statusType = 'error';
                $statusMessage = __('There are no new contacts');
            } elseif ($failedImports === 0) {
                $statusType = 'success';
                $statusMessage = __('All rows have been imported successfully!');
            } elseif ($successfulImports > 0 && $failedImports > 0) {
                $statusType = 'warning';
                $statusMessage = __('Some rows have been imported successfully, while others failed. Please check the error logs for details.');
            }

            return redirect('/contacts')->with(
                'status',
                [
                    'type' => $statusType,
                    'message' => $statusMessage,
                    'import_summary' => array(
                        'total_imports' => $totalImports,
                        'successful_imports' => $successfulImports,
                        'failed_imports' => $failedImports,
                        'duplicate_entries'  => $import->getFailedImportsDueToDuplicatesCount(),
                        'invalid_format_entries' => $import->getFailedImportsDueToFormat(),
                        'failed_rows_details' => $import->getFailedImports(),
                        'failed_limit_entries'  => $import->getFailedImportsDueToLimit(),
                    ),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Import Error: ' . $e->getMessage());
        }
    }

    public function store(StoreContact $request)
    {
        $contact = $this->contactService()->store($request);

        return redirect('/contacts?id=' . $contact->uuid)->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Contact added successfully!')
            ]
        );
    }

    public function update(StoreContact $request, $uuid)
    {
        $contact = $this->contactService()->store($request, $uuid);

        return redirect('/contacts/' . $contact->uuid)->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Contact updated successfully!')
            ]
        );
    }

    public function favorite(Request $request, $uuid)
    {
        $this->contactService()->favorite($request, $uuid);

        return redirect('/contacts/' . $uuid)->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Contact updated successfully!')
            ]
        );
    }

    public function delete(Request $request)
    {
        $uuids = $request->input('uuids', []);
        $this->contactService()->delete($uuids);

        return redirect('/contacts')->with(
            'status',
            [
                'type' => 'success',
                'message' => __('Contact(s) deleted successfully')
            ]
        );
    }

    private function getLocationSettings()
    {
        // Retrieve the settings for the current organization
        $settings = Organization::where('id', session()->get('current_organization'))->first();

        if ($settings) {
            // Decode the JSON metadata column into an associative array
            $metadata = json_decode($settings->metadata, true);

            if (isset($metadata['contacts'])) {
                // If the 'contacts' key exists, retrieve the 'location' value
                $location = $metadata['contacts']['location'] ?? 'after';

                // Now, you have the location value available
                return $location;
            } else {
                return null;
            }
        } else {
            return null;
        }
    }


    public function updateLabels(Request $request, $uuid)
    {

        $contact = Contact::where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->whereNull('deleted_at')
            ->firstOrFail();

        $validated = $request->validate([
            'labels' => 'array',
            'labels.*' => 'exists:labels,id',
        ]);

        // sync keeps only the selected labels (removes unselected ones)
        $contact->labels()->sync($validated['labels'] ?? []);

        if ($request->ajax() || $request->expectsJson()) {
            $contact->load('labels');

            return response()->json([
                'success' => true,
                'message' => 'Labels updated successfully.',
                'labels' => $contact->labels,
            ]);
        }

        return back()->with('success', 'Labels updated successfully.');
    }

    public function getLabels($uuid)
    {
        $contact = Contact::with('labels')->where('uuid', $uuid)
            ->where('organization_id', session()->get('current_organization'))
            ->firstOrFail();

        $availableLabels = \App\Models\Label::where('organization_id', session()->get('current_organization'))
            ->orderBy('title')
            ->get();

        return response()->json([
            'availableLabels' => $availableLabels,
            'contactLabels' => $contact->labels
        ]);
    }

    public function toggleBot(Request $request, $uuid)
    {
        $organizationId = $this->getCurrentOrganizationId();

        // Validate the request
        $validated = $request->validate([
            'bot_enable' => 'required|boolean'
        ]);

        // Find the contact
        $contact = Contact::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Update the bot_enable field
        $contact->bot_enable = $validated['bot_enable'];
        $contact->updated_at = now();
        $contact->save();

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Bot status updated successfully!')
        ]);
    }

    public function chatViewDetails(Request $request)
    {
        $organizationId = $this->getCurrentOrganizationId();

        $validated = $request->validate([
            'phone' => 'required|string',
            'name'  => 'nullable|string',
        ]);

        $phone = preg_replace('/\s+/', '', $validated['phone']); // remove all whitespace
        $phone = trim($phone);

        $contact = Contact::withTrashed()->where('organization_id', $organizationId)
            ->where('phone', $phone)
            ->whereNull('deleted_at')
            ->first();

        if ($contact) {
            if ($contact->trashed()) {
                $contact->restore();
            }
        } else {
            $contact = Contact::create([
                'organization_id' => $organizationId,
                'phone'           => $phone,
                'first_name'            => $validated['name'] ?? 'Unknown Contact',
                'is_subscribed' => 1,
                'last_name' => null,
                'email' => null,
                'created_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
                'bot_enable' => 1
            ]);
        }


        return response()->json([
            'redirect' => '/contacts?id=' . $contact->uuid,
        ]);
    }
}
