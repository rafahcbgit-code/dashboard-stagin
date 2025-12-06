<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'identifier' => 'test-org-'.Str::random(8),
            'name' => $this->faker->company(),
            'address' => $this->faker->address(),
            'timezone' => 'UTC',
            'created_by' => 1,
            'metadata' => json_encode([
                'whatsapp' => [
                    'access_token' => 'test_access_token_'.Str::random(32),
                    'phone_number_id' => 'test_phone_'.Str::random(10),
                    'waba_id' => 'test_waba_'.Str::random(10),
                    'app_secret' => 'test_app_secret_'.Str::random(32),
                ],
                'timezone' => 'UTC',
            ]),
        ];
    }

    /**
     * State for organization with valid WhatsApp signature secret.
     */
    public function withAppSecret(string $secret): static
    {
        return $this->state(function (array $attributes) use ($secret) {
            $metadata = json_decode($attributes['metadata'], true);
            $metadata['whatsapp']['app_secret'] = $secret;

            return [
                'metadata' => json_encode($metadata),
            ];
        });
    }

    /**
     * State for organization without app secret (skip signature validation).
     */
    public function withoutAppSecret(): static
    {
        return $this->state(function (array $attributes) {
            $metadata = json_decode($attributes['metadata'], true);
            unset($metadata['whatsapp']['app_secret']);

            return [
                'metadata' => json_encode($metadata),
            ];
        });
    }

    /**
     * Task 2.7: State for load test organization with LoadTest- prefix.
     * Includes campaign batch settings for testing.
     */
    public function loadTest(int $number = 1): static
    {
        return $this->state(function (array $attributes) use ($number) {
            $metadata = json_decode($attributes['metadata'], true);
            $metadata['campaigns'] = [
                'batch_size' => 500,
                'enable_resend' => false,
                'resend_intervals' => [],
            ];

            return [
                'identifier' => 'LoadTest-'.Str::random(8),
                'name' => "LoadTest-Org-{$number}",
                'metadata' => json_encode($metadata),
            ];
        });
    }

    /**
     * Task 2.7: State for organization with custom campaign batch settings.
     */
    public function withCampaignSettings(int $batchSize = 500, bool $enableResend = false, array $resendIntervals = []): static
    {
        return $this->state(function (array $attributes) use ($batchSize, $enableResend, $resendIntervals) {
            $metadata = json_decode($attributes['metadata'], true);
            $metadata['campaigns'] = [
                'batch_size' => $batchSize,
                'enable_resend' => $enableResend,
                'resend_intervals' => $resendIntervals,
            ];

            return [
                'metadata' => json_encode($metadata),
            ];
        });
    }
}
