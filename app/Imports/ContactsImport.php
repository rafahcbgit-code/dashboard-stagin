<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\PhoneService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Support\Collection;

class ContactsImport extends \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder implements ToCollection, WithHeadingRow, WithCustomValueBinder, WithChunkReading
{
    protected $totalImports = 0;
    protected $successfulImports = 0;
    protected $failedImports = [];
    protected $failedImportsDueToFormat = 0;
    protected $failedImportsDueToDuplicates = 0;
    protected $failedImportsDueToLimit = 0;
    protected $organizationId;
    protected $userId;
    protected $contactLimit;
    protected $contactFields = [];
    protected $allExistingPhones = [];
    protected $groupsCache = [];
    protected $contactsBuffer = [];
    protected $contactGroupsBuffer = [];

    public function __construct()
    {
        $this->organizationId = session()->get('current_organization');
        $this->userId = auth()->user()->id;
        $this->contactLimit = $this->contactSubscriptionLimit($this->organizationId);
        
        $this->contactFields = Cache::remember(
            "contact_fields_{$this->organizationId}", 
            3600, 
            function() {
                return ContactField::where('organization_id', $this->organizationId)
                    ->pluck('name')
                    ->toArray();
            }
        );

        // Load ALL existing phones ONCE at the beginning
        $this->allExistingPhones = Contact::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->pluck('id', 'phone')
            ->toArray();

        // Pre-load all groups for this organization
        $groups = ContactGroup::where('organization_id', $this->organizationId)
            ->whereNull('deleted_at')
            ->get();
        
        foreach ($groups as $group) {
            $this->groupsCache[$group->name] = $group->id;
        }
    }

    public function collection(Collection $rows)
    {
        DB::connection()->disableQueryLog();
        
        foreach ($rows as $row) {
            $this->totalImports++;
            $this->processRow($row->toArray());
        }
        
        // Insert all buffered contacts at once
        $this->flushContactsBuffer();
        
        gc_collect_cycles();
    }

    protected function processRow(array $row)
    {
        try {
            $phoneNumberValue = $row['phone'] ?? '';

            if (empty($phoneNumberValue)) {
                $this->failedImports[] = [
                    'row' => 'Empty',
                    'error' => __('Phone number required!')
                ];
                $this->failedImportsDueToFormat++;
                return;
            }

            if (!str_starts_with($phoneNumberValue, '+')) {
                $phoneNumberValue = '+' . $phoneNumberValue;
            }

            if (!PhoneService::isValid($phoneNumberValue)) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Invalid format!')
                ];
                $this->failedImportsDueToFormat++;
                return;
            }

            $formattedPhone = PhoneService::getE164Format($phoneNumberValue);

            // Check if contact exists
            if (isset($this->allExistingPhones[$formattedPhone])) {
                $this->updateExistingContact($this->allExistingPhones[$formattedPhone], $row);
                return;
            }

            // Check contact limit
            if ($this->contactLimit > 0) {
                $totalCount = count($this->allExistingPhones) + $this->successfulImports;
                
                if ($totalCount >= $this->contactLimit) {
                    $this->failedImports[] = [
                        'row' => $row['phone'],
                        'error' => __('Contact limit reached!')
                    ];
                    $this->failedImportsDueToLimit++;
                    return;
                }
            }

            // Prepare metadata
            $metadata = [];
            foreach ($this->contactFields as $field) {
                $normalizedField = strtolower($field);
                if (isset($row[$normalizedField])) {
                    $metadata[$field] = $row[$normalizedField];
                }
            }

            $contactData = [
                'uuid'            => \Illuminate\Support\Str::uuid()->toString(),
                'organization_id' => $this->organizationId,
                'first_name'      => $row['first_name'] ?? 'name',
                'last_name'       => $row['last_name'] ?? null,
                'phone'           => $formattedPhone,
                'email'           => $row['email'] ?? null,
                'address'         => json_encode([
                    'street'  => $row['street'] ?? null,
                    'city'    => $row['city'] ?? null,
                    'state'   => $row['state'] ?? null,
                    'zip'     => $row['zip'] ?? null,
                    'country' => $row['country'] ?? null
                ]),
                'metadata'        => !empty($metadata) ? json_encode($metadata) : null,
                'created_by'      => $this->userId,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            $this->contactsBuffer[] = [
                'data' => $contactData,
                'groups' => $this->parseGroups($row['group_name'] ?? null)
            ];

            $this->successfulImports++;

            // Flush buffer every 1000 records
            if (count($this->contactsBuffer) >= 1000) {
                $this->flushContactsBuffer();
            }

        } catch (\Exception $e) {
            $this->failedImports[] = [
                'row' => $row['phone'] ?? 'Unknown',
                'error' => __('Invalid format!') . ' ' . $e->getMessage()
            ];
            $this->failedImportsDueToFormat++;
        }
    }

    protected function parseGroups($groupName)
    {
        if (empty($groupName)) {
            return [];
        }

        $groupNames = explode('|', $groupName);
        $groupNames = array_map('trim', $groupNames);
        $groupIds = [];

        foreach ($groupNames as $name) {
            if (!isset($this->groupsCache[$name])) {
                $group = ContactGroup::create([
                    'organization_id' => $this->organizationId,
                    'name'            => $name,
                    'created_by'      => $this->userId,
                ]);
                $this->groupsCache[$name] = $group->id;
            }
            $groupIds[] = $this->groupsCache[$name];
        }

        return $groupIds;
    }

    protected function flushContactsBuffer()
    {
        if (empty($this->contactsBuffer)) {
            return;
        }

        $contactsData = array_column($this->contactsBuffer, 'data');
        
        // Insert all contacts at once
        Contact::insert($contactsData);

        // Get the inserted contact IDs
        $phones = array_column($contactsData, 'phone');
        $insertedContacts = Contact::where('organization_id', $this->organizationId)
            ->whereIn('phone', $phones)
            ->pluck('id', 'phone')
            ->toArray();

        // Prepare pivot data for groups
        $pivotData = [];
        foreach ($this->contactsBuffer as $buffer) {
            $phone = $buffer['data']['phone'];
            if (isset($insertedContacts[$phone]) && !empty($buffer['groups'])) {
                foreach ($buffer['groups'] as $groupId) {
                    $pivotData[] = [
                        'contact_id' => $insertedContacts[$phone],
                        'contact_group_id' => $groupId,
                    ];
                }
            }
        }

        // Insert all group associations at once
        if (!empty($pivotData)) {
            // Remove duplicates
            $pivotData = array_map('unserialize', array_unique(array_map('serialize', $pivotData)));
            DB::table('contact_contact_group')->insert($pivotData);
        }

        // Clear buffer
        $this->contactsBuffer = [];
    }

    protected function updateExistingContact($contactId, array $row)
    {
        // Get existing contact to preserve old metadata
        $existingContact = Contact::find($contactId);
        $existingMetadata = $existingContact && $existingContact->metadata 
            ? json_decode($existingContact->metadata, true) 
            : [];

        // Prepare new metadata from row
        $newMetadata = [];
        foreach ($this->contactFields as $field) {
            $normalizedField = strtolower($field);
            if (isset($row[$normalizedField])) {
                $newMetadata[$field] = $row[$normalizedField];
            }
        }

        // Merge: new values override old ones, but keep old values for fields not in import
        $mergedMetadata = array_merge($existingMetadata, $newMetadata);

        $updateData = [
            'first_name' => $row['first_name'] ?? 'name',
            'last_name'  => $row['last_name'] ?? null,
            'email'      => $row['email'] ?? null,
            'address'    => json_encode([
                'street'  => $row['street'] ?? null,
                'city'    => $row['city'] ?? null,
                'state'   => $row['state'] ?? null,
                'zip'     => $row['zip'] ?? null,
                'country' => $row['country'] ?? null
            ]),
            'metadata'   => !empty($mergedMetadata) ? json_encode($mergedMetadata) : null,
            'updated_at' => now(),
        ];

        Contact::where('id', $contactId)->update($updateData);

        if (!empty($row['group_name'])) {
            $groupIds = $this->parseGroups($row['group_name']);
            
            if (!empty($groupIds)) {
                // Get existing group associations
                $existingGroups = DB::table('contact_contact_group')
                    ->where('contact_id', $contactId)
                    ->pluck('contact_group_id')
                    ->toArray();

                // Only insert new associations
                $newGroups = array_diff($groupIds, $existingGroups);
                
                if (!empty($newGroups)) {
                    $pivotData = [];
                    foreach ($newGroups as $groupId) {
                        $pivotData[] = [
                            'contact_id' => $contactId,
                            'contact_group_id' => $groupId,
                        ];
                    }
                    DB::table('contact_contact_group')->insert($pivotData);
                }
            }
        }
    }

    public function getFailedImportsDueToFormat()
    {
        return $this->failedImportsDueToFormat;
    }

    public function getFailedImportsDueToDuplicatesCount()
    {
        return $this->failedImportsDueToDuplicates;
    }

    public function getFailedImportsDueToLimit()
    {
        return $this->failedImportsDueToLimit;
    }

    public function getSuccessfulImports()
    {
        return $this->successfulImports;
    }

    public function getFailedImports()
    {
        return $this->failedImports;
    }

    public function getTotalImportsCount()
    {
        return $this->totalImports;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    private function contactSubscriptionLimit($organizationId)
    {
        $subscription = Subscription::where('organization_id', $organizationId)->first();
        
        if (!$subscription) {
            return 0;
        }
        
        $usageLimit = 0;
        
        if ($subscription->status === 'trial' && $subscription->valid_until > now()) {
            $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
            $usageLimit = $limit ? json_decode($limit, true)['contacts'] ?? 0 : 0;
        } else {
            $subscriptionPlan = SubscriptionPlan::find($subscription->plan_id);
            
            if ($subscriptionPlan) {
                $subscriptionPlanLimits = json_decode($subscriptionPlan->metadata, true);
                $usageLimit = $subscriptionPlanLimits['contacts_limit'] ?? 0;
            }
        }

        return $usageLimit;
    }
}