<?php

namespace Database\Factories;

use App\Models\ContactGroup;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContactGroup>
 */
class ContactGroupFactory extends Factory
{
    protected $model = ContactGroup::class;

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
            'name' => $this->faker->words(2, true).' Group',
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];
    }

    /**
     * Group for a specific organization.
     */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organization->id,
        ]);
    }

    /**
     * Deleted group.
     */
    public function deleted(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Failed contacts group (used for campaign retry failures).
     */
    public function forFailedContacts(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Failed Campaign Contacts',
        ]);
    }
}
