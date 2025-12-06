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
}
