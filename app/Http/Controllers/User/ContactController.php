<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Label;
use App\Models\Team;
use App\Models\Organization;
use App\Exports\ContactsExport;
use App\Http\Requests\StoreContact;
use App\Http\Resources\ContactResource;
use App\Imports\ContactsImport;
use App\Services\ContactGroupCountService;
use App\Services\ContactService;
use App\Support\Filters\ContactFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        if ($uuid === 'export') {
            $format = $request->query('format', 'xlsx');
            return $this->exportContacts($format);
        }

        if ($uuid === 'template') {
            return $this->downloadTemplate();
        }

        $selectedUuid = $request->query('id') ?: $uuid;
        $editContact = $request->query('edit') === 'true';
        $perPage = max(10, min((int) $request->query('per_page', 20), 100));

        $contactsQuery = Contact::query()
            ->where('contacts.organization_id', $organizationId)
            ->whereNull('contacts.deleted_at')
            ->with(['contactGroups:id,name', 'labels:id,title,color'])
            ->withCount([
                'chats as unread_messages_count' => function (Builder $query): void {
                    $query->where('type', 'inbound')
                        ->whereNull('deleted_at')
                        ->where('is_read', 0);
                },
            ])
            ->select('contacts.*');

        $contactsQuery = (new ContactFilter())->apply($contactsQuery, $request);

        $contacts = $contactsQuery
            ->orderByDesc('contacts.is_favorite')
            ->orderByDesc('contacts.created_at')
            ->paginate($perPage)
            ->withQueryString();

        $rowCount = Contact::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->count();

        $contactGroups = ContactGroup::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->withCount(['contacts as contacts_count' => function (Builder $query) use ($organizationId): void {
                $query->where('contacts.organization_id', $organizationId)
                    ->whereNull('contacts.deleted_at');
            }])
            ->orderBy('name')
            ->get(['id', 'uuid', 'name']);

        $labels = Label::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('title')
            ->get(['id', 'title', 'color']);

        $contact = Contact::with(['contactGroups:id,name', 'labels:id,title,color'])
            ->where('uuid', $selectedUuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->first();

        $contactFields = ContactField::where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('position')
            ->get(['id', 'name', 'type', 'value', 'required', 'uuid']);

        $team = Team::where('organization_id', $organizationId)
            ->where('user_id', auth()->user()->id)
            ->first();

        return Inertia::render('User/Contact/Index', [
            'title' => __('Contacts'),
            'rows' => ContactResource::collection($contacts),
            'rowCount' => $rowCount,
            'filteredCount' => $contacts->total(),
            'contact' => $contact ? ContactResource::make($contact)->resolve() : null,
            'fields' => $contactFields,
            'contactGroups' => $contactGroups,
            'labels' => $labels,
            'filters' => request()->all(),
            'locationSettings' => $this->getLocationSettings(),
            'editContact' => $editContact,
            'role' => $team->role ?? 'owner',
        ]);
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

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required_without:select_all_filtered', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'select_all_filtered' => ['nullable', 'boolean'],
            'except_ids' => ['nullable', 'array'],
            'except_ids.*' => ['integer'],
        ]);

        $organizationId = $this->getCurrentOrganizationId();
        $selectAllFiltered = (bool) $request->boolean('select_all_filtered');
        $exceptIds = collect($validated['except_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        $query = Contact::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        if ($selectAllFiltered) {
            $query = (new ContactFilter())->apply($query, $this->toFilterRequest($request));
            if ($exceptIds->isNotEmpty()) {
                $query->whereNotIn('contacts.id', $exceptIds->all());
            }
        } else {
            $query->whereIn('contacts.id', $validated['ids'] ?? []);
        }

        $this->contactService()->deleteByQuery($query);

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Selected contacts deleted successfully.'),
        ]);
    }

    public function bulkUpdateAttribute(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required_without:select_all_filtered', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'select_all_filtered' => ['nullable', 'boolean'],
            'except_ids' => ['nullable', 'array'],
            'except_ids.*' => ['integer'],
            'action' => ['required', 'in:add_group,remove_group,add_label,remove_label,change_subscription_status'],
            'filter_group_ids' => ['nullable', 'array'],
            'filter_group_ids.*' => ['integer'],
            'filter_subscription_status' => ['nullable', 'in:subscribed,unsubscribed,0,1'],
            'group_ids' => ['array'],
            'group_ids.*' => ['integer'],
            'label_ids' => ['array'],
            'label_ids.*' => ['integer'],
            'subscription_status' => ['nullable', 'in:0,1'],
        ]);

        $organizationId = $this->getCurrentOrganizationId();
        $selectAllFiltered = (bool) $request->boolean('select_all_filtered');
        $exceptIds = collect($validated['except_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        $contactsQuery = Contact::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->select('contacts.*');

        if ($selectAllFiltered) {
            $contactsQuery = (new ContactFilter())->apply($contactsQuery, $this->toFilterRequest($request));
            if ($exceptIds->isNotEmpty()) {
                $contactsQuery->whereNotIn('contacts.id', $exceptIds->all());
            }
        } else {
            $contactsQuery->whereIn('contacts.id', $validated['ids'] ?? []);
        }

        if (!(clone $contactsQuery)->exists()) {
            return back()->with('status', [
                'type' => 'warning',
                'message' => __('No contacts matched the selected rows.'),
            ]);
        }

        $action = $validated['action'];
        $groupIds = collect($validated['group_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        $labelIds = collect($validated['label_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        if ($groupIds->isNotEmpty()) {
            $allowedGroupIds = ContactGroup::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $groupIds->all())
                ->pluck('id');
            $groupIds = $allowedGroupIds->values();
        }

        if ($labelIds->isNotEmpty()) {
            $allowedLabelIds = Label::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $labelIds->all())
                ->pluck('id');
            $labelIds = $allowedLabelIds->values();
        }

        if ($action === 'change_subscription_status') {
            (clone $contactsQuery)
                ->update([
                    'is_subscribed' => (int) ($validated['subscription_status'] ?? 1),
                    'updated_at' => now(),
                ]);
        } else {
            (clone $contactsQuery)->chunkById(500, function ($contacts) use ($action, $groupIds, $labelIds) {
                foreach ($contacts as $contact) {
                    if ($action === 'add_group' && $groupIds->isNotEmpty()) {
                        $contact->contactGroups()->syncWithoutDetaching($groupIds->all());
                    }

                    if ($action === 'remove_group' && $groupIds->isNotEmpty()) {
                        $contact->contactGroups()->detach($groupIds->all());
                    }

                    if ($action === 'add_label' && $labelIds->isNotEmpty()) {
                        $contact->labels()->syncWithoutDetaching($labelIds->all());
                    }

                    if ($action === 'remove_label' && $labelIds->isNotEmpty()) {
                        $contact->labels()->detach($labelIds->all());
                    }
                }
            }, 'contacts.id', 'id');

            if (in_array($action, ['add_group', 'remove_group'], true)) {
                ContactGroupCountService::rebuildGroups($groupIds);
            }
        }

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Bulk action applied successfully.'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $organizationId = $this->getCurrentOrganizationId();
        $ids = collect($request->query('ids', []))->map(fn ($id) => (int) $id)->filter()->values();
        $scope = (string) $request->query('scope', 'filtered');
        $selectAllFiltered = (bool) $request->boolean('select_all_filtered');
        $exceptIds = collect($request->query('except_ids', []))->map(fn ($id) => (int) $id)->filter()->values();

        $query = Contact::query()
            ->with(['contactGroups:id,name', 'labels:id,title'])
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->select([
                'id',
                'first_name',
                'last_name',
                'phone',
                'email',
                'is_subscribed',
                'address',
                'metadata',
                'created_at',
            ]);

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids->all());
        } elseif ($selectAllFiltered) {
            $query = (new ContactFilter())->apply($query, $request);
            if ($exceptIds->isNotEmpty()) {
                $query->whereNotIn('contacts.id', $exceptIds->all());
            }
        } elseif ($scope !== 'all') {
            $query = (new ContactFilter())->apply($query, $request);
        }

        $contactFields = ContactField::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ];

        $fileName = $scope === 'all'
            ? 'contacts-all-export.csv'
            : ($ids->isNotEmpty() ? 'contacts-selected-export.csv' : 'contacts-export.csv');

        return response()->streamDownload(function () use ($query, $contactFields) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, array_merge([
                'First Name',
                'Last Name',
                'Phone',
                'Email',
                'Subscription',
                'Groups',
                'Labels',
                'Country',
                'Created At',
            ], $contactFields));

            $query->orderBy('id')->chunkById(500, function ($contacts) use ($handle, $contactFields) {
                foreach ($contacts as $contact) {
                    $address = json_decode((string) $contact->address, true) ?: [];
                    $metadata = json_decode((string) $contact->metadata, true) ?: [];

                    $row = [
                        $contact->first_name,
                        $contact->last_name,
                        $contact->formatted_phone_number,
                        $contact->email,
                        (int) $contact->is_subscribed === 1 ? 'Subscribed' : 'Unsubscribed',
                        $contact->contactGroups->pluck('name')->implode('|'),
                        $contact->labels->pluck('title')->implode('|'),
                        $address['country'] ?? null,
                        $contact->created_at,
                    ];

                    foreach ($contactFields as $fieldName) {
                        $row[] = $metadata[$fieldName] ?? null;
                    }

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, $fileName, $headers);
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

        // Cancel any pending auto-reenable
        \App\Models\ContactBotReenable::where('contact_id', $contact->id)->delete();

        return back()->with('status', [
            'type' => 'success',
            'message' => __('Bot status updated successfully!')
        ]);
    }

    private function toFilterRequest(Request $request): Request
    {
        $filters = $request->all();
        $action = (string) $request->input('action', '');

        if ($request->has('filter_group_ids')) {
            $filters['group_ids'] = $request->input('filter_group_ids');
        } elseif (in_array($action, ['add_group', 'remove_group'], true)) {
            unset($filters['group_ids']);
        }

        if ($request->has('filter_subscription_status')) {
            $filters['subscription_status'] = $request->input('filter_subscription_status');
        } elseif ($action === 'change_subscription_status') {
            unset($filters['subscription_status']);
        }

        unset(
            $filters['action'],
            $filters['ids'],
            $filters['except_ids'],
            $filters['select_all_filtered'],
            $filters['filter_group_ids'],
            $filters['filter_subscription_status'],
            $filters['label_ids']
        );

        return Request::create('/contacts', 'GET', $filters);
    }

    public function toggleBlock(Request $request, $uuid)
    {
        $organizationId = $this->getCurrentOrganizationId();

        $validated = $request->validate([
            'is_blocked' => 'required|boolean',
        ]);

        $contact = Contact::where('uuid', $uuid)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $contact->is_blocked = $validated['is_blocked'];
        $contact->updated_at = now();
        $contact->save();

        return back()->with('status', [
            'type' => 'success',
            'message' => $validated['is_blocked']
                ? __('Contact blocked successfully.')
                : __('Contact unblocked successfully.'),
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
                'contact_source' => 'platform',
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
