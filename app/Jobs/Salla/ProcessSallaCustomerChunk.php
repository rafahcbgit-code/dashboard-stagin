<?php

namespace App\Jobs\Salla;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ProcessSallaCustomerChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(
        private readonly int     $organizationId,
        private readonly array   $customers,
        private readonly ?string $groupName = null
    ) {}

    public function handle(): void
    {
        // 1️⃣ Extract the maximum group size based on messaging_limit_tier
        $tierLimit = $this->resolveTierLimit();
        dd($tierLimit);

        // 2️⃣ Upsert contacts first
        $phones = array_column($this->customers, 'phone');

        $existingPhones = Contact::where('organization_id', $this->organizationId)
            ->whereIn('phone', $phones)
            ->pluck('phone')
            ->flip();

        $batch = [];
        $now   = now();

        foreach ($this->customers as $customer) {
            $batch[] = [
                'organization_id' => $this->organizationId,
                'phone'           => $customer['phone'],
                'first_name'      => $customer['first_name'],
                'email'           => $customer['email'],
                'uuid'            => Str::uuid(),
                'created_by'      => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        Contact::upsert(
            $batch,
            ['organization_id', 'phone'],
            ['first_name', 'email', 'updated_at']
        );

        // 3️⃣ Assign contacts to groups while respecting the tier limit
        if ($this->groupName) {
            $contactIds = Contact::where('organization_id', $this->organizationId)
                ->whereIn('phone', $phones)
                ->pluck('id')
                ->values()
                ->all();

            $this->assignContactsToGroups($contactIds, $tierLimit);
        }

        Log::info('Salla chunk processed', [
            'organization_id' => $this->organizationId,
            'group'           => $this->groupName,
            'tier_limit'      => $tierLimit,
            'total'           => count($batch),
            'updated'         => count($existingPhones),
            'inserted'        => count($batch) - count($existingPhones),
        ]);
    }

    /**
     * Resolve the maximum allowed group size from messaging_limit_tier
     * Example: TIER_250 → 250 | null → unlimited (PHP_INT_MAX)
     */
    private function resolveTierLimit(): int
    {
        $organization = Organization::find($this->organizationId);

        if (!$organization) {
            return 250;
        }

        $metadata = $organization->metadata;

        // If metadata is stored as a JSON string, decode it into an array
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        if (!is_array($metadata)) {
            return 250;
        }

        $tier = $metadata['whatsapp']['messaging_limit_tier'] ?? null;

        if (!$tier) {
            return 250;
        }

        // Support TIER_UNLIMITED
        if (strtoupper($tier) === 'TIER_UNLIMITED') {
            return PHP_INT_MAX;
        }

        // Support TIER_250 / TIER_10K / TIER_100K
        if (preg_match('/TIER_(\d+)(K?)/i', $tier, $matches)) {

            $number = (int) $matches[1];
            $suffix = strtoupper($matches[2] ?? '');

            if ($suffix === 'K') {
                return $number * 1000;
            }

            return $number;
        }

        return 250;
    }

    /**
     * Distribute contacts across groups according to the tier limit.
     *
     * Strategy:
     * - Fetch existing groups ordered (Group 1, Group 2, ...)
     * - Fill each group up to its limit
     * - Create new groups when capacity is exceeded
     */
    private function assignContactsToGroups(array $contactIds, int $tierLimit): void
    {
        if (empty($contactIds)) {
            return;
        }

        // Retrieve all groups matching the base name (e.g., "Group", "Group 2", "Group 3", ...)
        $existingGroups = ContactGroup::where('organization_id', $this->organizationId)
            ->where(function ($q) {
                $q->where('name', $this->groupName)
                  ->orWhere('name', 'like', $this->groupName . ' %');
            })
            ->withCount('contacts')
            ->orderBy('id')
            ->get();

        $remaining = $contactIds;

        foreach ($existingGroups as $group) {
            if (empty($remaining)) break;

            $available = $tierLimit - $group->contacts_count;
            if ($available <= 0) continue;

            $toAttach = array_splice($remaining, 0, $available);
            $group->contacts()->syncWithoutDetaching($toAttach);
        }

        // If there are still contacts remaining, create new groups
        $groupIndex = $existingGroups->count() + 1;

        foreach (array_chunk($remaining, $tierLimit) as $chunk) {
            $newGroupName = $groupIndex === 1
                ? $this->groupName
                : $this->groupName . ' ' . $groupIndex;

            $newGroup = ContactGroup::firstOrCreate(
                [
                    'organization_id' => $this->organizationId,
                    'name'            => $newGroupName,
                ],
                [
                    'created_by' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $newGroup->contacts()->syncWithoutDetaching($chunk);

            $groupIndex++;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Salla chunk job failed', [
            'organization_id' => $this->organizationId,
            'error'           => $e->getMessage(),
            'chunk_size'      => count($this->customers),
        ]);
    }
}