<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CampaignLog>
 */
class CampaignLogFactory extends Factory
{
    protected $model = CampaignLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'contact_id' => Contact::factory(),
            'chat_id' => null,
            'metadata' => null,
            'status' => 'pending',
            'retry_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Log in pending state (waiting to be sent).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Log in ongoing state (currently being processed).
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ongoing',
        ]);
    }

    /**
     * Log in success state (sent successfully).
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'metadata' => json_encode(['message_id' => 'wam_'.$this->faker->uuid()]),
        ]);
    }

    /**
     * Log in failed state (failed to send).
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'metadata' => json_encode(['error' => 'Test error message']),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Log for a specific campaign.
     */
    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Log for a specific contact.
     */
    public function forContact(Contact $contact): static
    {
        return $this->state(fn (array $attributes) => [
            'contact_id' => $contact->id,
        ]);
    }

    /**
     * Log with retry count.
     */
    public function withRetries(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'retry_count' => $count,
        ]);
    }
}
