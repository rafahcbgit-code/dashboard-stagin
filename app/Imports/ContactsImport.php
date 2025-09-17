<?php

namespace App\Imports;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ContactGroup;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Rules\ContactLimit;
use App\Services\PhoneService;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use App\Models\ContactContactGroup;

class ContactsImport extends \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder implements ToModel, WithHeadingRow, WithCustomValueBinder, WithChunkReading
{
    protected $totalImports = 0;
    protected $successfulImports = 0;
    protected $failedImports = [];
    protected $failedImportsDueToFormat = 0;
    protected $failedImportsDueToDuplicates = 0;
    protected $failedImportsDueToLimit = 0;

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        try {
            $this->totalImports++;

            $phoneNumberValue = $row['phone'];

            if (!str_starts_with($phoneNumberValue, '+')) {
                $phoneNumberValue = '+' . $phoneNumberValue;
            }

            // Sequential Validation: First check the first_name field
            $validator = Validator::make($row, [
                'first_name' => 'required', // Make first_name required
            ]);

            // If first_name fails, stop further validation and return the error
            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('First name required!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // If first_name passes, proceed to validate the phone field
            $validator = Validator::make($row, [
                'phone' => 'required', // Make phone required
            ]);

            // If phone is invalid, add the error and return
            if ($validator->fails()) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Phone number required!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // Now validate the phone number format
            if (!PhoneService::isValid($phoneNumberValue)) {
                $this->failedImports[] = [
                    'row' => $row['phone'],
                    'error' => __('Invalid format!')
                ];
                $this->failedImportsDueToFormat++;
                return null;
            }

            // Check if the phone number already exists in the database
            $formattedPhone = PhoneService::getE164Format($phoneNumberValue);

            // Get current organization ID
            $organizationId = session()->get('current_organization');

            // Check if the phone number already exists
            $existingContact = Contact::where('organization_id', $organizationId)
                ->where('phone', $formattedPhone)
                ->whereNull('deleted_at')
                ->first();

                if ($existingContact) {
                $existingContact->update([
                    'first_name' => $row['first_name'] ?? $existingContact->first_name,
                    'last_name'  => $row['last_name'] ?? $existingContact->last_name,
                    'email'      => $row['email'] ?? $existingContact->email,
                    'address'    => json_encode([
                        'street'  => $row['street'] ?? null,
                        'city'    => $row['city'] ?? null,
                        'state'   => $row['state'] ?? null,
                        'zip'     => $row['zip'] ?? null,
                        'country' => $row['country'] ?? null
                    ]),
                ]);
            }

            if ($existingContact && !empty($row['group_name'])) {
                // Split group names by "|" and trim whitespace
                $groupNames = explode('|', $row['group_name'] ?? '');
                $groupNames = array_map('trim', $groupNames);

                foreach ($groupNames as $groupName) {
                    // Find or create the contact group
                    $group = ContactGroup::firstOrCreate(
                        [
                            'organization_id' => $organizationId,
                            'name'            => $groupName,
                            'deleted_at'      => null
                        ],
                        [
                            'created_by' => auth()->user()->id,
                        ]
                    );

                    // Check if the contact is already in this group
                    $existsInGroup = ContactContactGroup::where('contact_group_id', $group->id)
                        ->where('contact_id', $existingContact->id)
                        ->exists();

                    if (!$existsInGroup) {
                        // Attach the contact to the group
                        $existingContact->contactGroups()->attach($group->id);
                    }
                }

                // Return null because we did not create a new contact,
                // we only added the existing one to the groups
                return null;
            }

            // Fetch dynamic fields from contact_fields table
            $organizationId = session()->get('current_organization');
            $contactLimit = $this->contactSubscriptionLimit($organizationId);

            // Check if the total contacts would exceed the limit using atomic operation
            if ($contactLimit > 0) {
                $existingContactCount = Contact::where('organization_id', $organizationId)->whereNull('deleted_at')->count();
        
                if (($existingContactCount + 1) > $contactLimit) {
                    $this->failedImports[] = [
                        'row' => $row['phone'],
                        'error' => __('Contact limit reached!')
                    ];
                    $this->failedImportsDueToLimit++;
                    return null;
                }
            }

            // Fetch dynamic fields from contact_fields table
            $contactFields = ContactField::where('organization_id', $organizationId)->pluck('name')->toArray();

            $metadata = [];

            foreach ($contactFields as $field) {
                $normalizedField = strtolower($field); 

                if (isset($row[$normalizedField])) {
                    $metadata[$field] = $row[$normalizedField];
                }
            }
            $contact = null ;
         
        if (!$existingContact) {
            
                    $contact =  Contact::create([
                        'organization_id'  => $organizationId,
                        'first_name'  => $row['first_name'],
                        'last_name'   => $row['last_name'],
                        'phone'       => PhoneService::getE164Format($phoneNumberValue), 
                        'email'       => $row['email'],
                        'address'     => json_encode([
                            'street'  => $row['street'] ?? null,
                            'city'    => $row['city'] ?? null,
                            'state'   => $row['state'] ?? null,
                            'zip'     => $row['zip'] ?? null,
                            'country' => $row['country'] ?? null
                        ]),
                        'metadata'    => !empty($metadata) ? json_encode($metadata) : null, 
                        'created_by'  => auth()->user()->id,
                    ]);
                }
                    if($contact){
                        $this->successfulImports++;

                        // Process contact groups (if provided)
                        if (!empty($row['group_name'])) {
                            $groupNames = explode('|', $row['group_name'] ?? ''); // Split by comma
                            $groupNames = array_map('trim', $groupNames); // Trim whitespace

                            foreach ($groupNames as $groupName) {
                                $group = ContactGroup::firstOrCreate([
                                    'organization_id' => $organizationId,
                                    'name'            => $groupName,
                                    'deleted_at'      => null
                                ], [
                                    'created_by' => auth()->user()->id,
                                ]);

                                // Attach contact to the group via pivot table
                                $contact->contactGroups()->attach($group->id);
                            }
                        }

                        return $contact;
                    }
                } catch (\Exception $e) {
                    $this->failedImports[] = [
                        'row' => $row['phone'],
                        'error' => __('Invalid format!')
                    ];
                    $this->failedImportsDueToFormat++;

                    return $contact;
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
                return 1000; // Adjust the chunk size as needed
            }

            private function contactSubscriptionLimit($organizationId)
            {
                $subscription = Subscription::where('organization_id', $organizationId)->first();
                
                // Default to 0 if no subscription found
                if (!$subscription) {
                    return 0;
                }
                
                $usageLimit = 0; // Default limit
                
                // Check trial status first
                if ($subscription->status === 'trial' && $subscription->valid_until > now()) {
                    $limit = optional(Setting::where('key', 'trial_limits')->first())->value;
                    $usageLimit = $limit ? json_decode($limit, true)['contacts'] ?? 0 : 0;
                } else {
                    // Check subscription plan
                    $subscriptionPlan = SubscriptionPlan::find($subscription->plan_id);
                    
                    if ($subscriptionPlan) {
                        $subscriptionPlanLimits = json_decode($subscriptionPlan->metadata, true);
                        $usageLimit = $subscriptionPlanLimits['contacts_limit'] ?? 0;
                    }
                }

                return $usageLimit;
            }
        }