<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ScientificPhOmanAgentsSeeder extends Seeder
{
    private const ORGANIZATION_ID = 402;

    public function run(): void
    {
        $emails = [
            'OmSaham@ScientificPh.Om',
            'OmShinas@ScientificPh.Om',
            'OmSohar1@ScientificPh.Om',
            'OmTharmad@ScientificPh.Om',
            'OmBarka@ScientificPh.Om',
            'ombawadimall@ScientificPh.Om',
            'OmSur@ScientificPh.Om',
            'OmSalalah1@ScientificPh.Om',
            'OmSalalahqof@ScientificPh.Om',
            'omkhuwair@ScientificPh.Om',
            'Omkhwaircc@ScientificPh.Om',
            'OmOasisMallKhwair@ScientificPh.Om',
            'OmPlanetGoubra@ScientificPh.Om',
            'OmGhoubra@ScientificPh.Om',
            'OmPlanetBowsher@ScientificPh.Om',
            'OmBowsher3@ScientificPh.Om',
            'OmBowsher2@ScientificPh.Om',
            'OmHail@ScientificPh.Om',
            'OmTheVillage@Scientificph.om',
            'OmSeeb@ScientificPh.Om',
            'OmPlanetKhoudh7@ScientificPh.Om',
            'OmPlanetKhoudh6@ScientificPh.Om',
            'OmAzaiba@ScientificPh.Om',
            'OmQurum@ScientificPh.Om',
            'OmWadihatat@ScientificPh.Om',
            'OmWadikabir@ScientificPh.Om',
            'OmAmerat@ScientificPh.Om',
            'OmAnsab@ScientificPh.Om',
            'OmPlanetGhala@ScientificPh.Om',
            'OmKmtRuwi@ScientificPh.Om',
            'OmMallofOman@ScientificPh.Om',
            'OmPanoramaMall@ScientificPh.Om',
            'OmHillsAvenuePlaza@ScientificPh.Om',
            'OmMobella@ScientificPh.Om',
        ];

        $password = 'ScientificPh@2026';
        $hashedPassword = Hash::make($password);

        DB::transaction(function () use ($emails, $hashedPassword) {
            if (! Organization::query()->whereKey(self::ORGANIZATION_ID)->exists()) {
                throw new \RuntimeException('Organization ID ' . self::ORGANIZATION_ID . ' does not exist.');
            }

            $createdBy = Team::query()
                ->where('organization_id', self::ORGANIZATION_ID)
                ->where('role', 'owner')
                ->whereNull('deleted_at')
                ->value('user_id');

            foreach ($emails as $email) {
                $user = User::withTrashed()
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->first();

                $firstName = strstr($email, '@', true);

                if ($user) {
                    $user->restore();
                    $user->forceFill([
                        'first_name' => $user->first_name ?: $firstName,
                        'last_name' => $user->last_name ?: 'Scientific Pharmacy',
                        'email' => $email,
                        'password' => $hashedPassword,
                        'role' => 'user',
                        'status' => 1,
                        'email_verified_at' => $user->email_verified_at ?: now(),
                        'code_verified' => 1,
                    ])->save();
                } else {
                    $user = new User();
                    $user->forceFill([
                        'first_name' => $firstName,
                        'last_name' => 'Scientific Pharmacy',
                        'email' => $email,
                        'password' => $hashedPassword,
                        'role' => 'user',
                        'status' => 1,
                        'email_verified_at' => now(),
                        'code_verified' => 1,
                    ]);
                    $user->save();
                }

                Team::withTrashed()->updateOrCreate(
                    [
                        'organization_id' => self::ORGANIZATION_ID,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => 'agent',
                        'status' => 'active',
                        'created_by' => $createdBy ?: $user->id,
                        'deleted_at' => null,
                    ]
                );
            }
        });
    }
}
