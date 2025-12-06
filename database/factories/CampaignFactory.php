<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'organization_id' => Organization::factory(),
            'name' => $this->faker->sentence(3),
            'template_id' => 1,
            'contact_group_id' => 0, // 0 means "all contacts" (no specific group)
            'metadata' => json_encode([
                'template_name' => 'test_template',
                'template_language' => 'en',
            ]),
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5), // Scheduled in the past to be picked up
            'created_by' => 1,
            'deleted_by' => null,
            'created_at' => now(),
            'deleted_at' => null,
        ];
    }

    /**
     * Campaign in scheduled state (waiting to be processed).
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Campaign in ongoing state (being processed).
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ongoing',
        ]);
    }

    /**
     * Campaign in completed state.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Campaign scheduled for the future (should NOT be picked up).
     */
    public function scheduledFuture(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
            'scheduled_at' => now()->addHours(2),
        ]);
    }

    /**
     * Deleted campaign.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
            'deleted_by' => 1,
        ]);
    }

    /**
     * Campaign for a specific organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }
}
