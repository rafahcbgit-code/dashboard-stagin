<?php

namespace Database\Factories;

use App\Models\CampaignLog;
use App\Models\CampaignLogRetry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CampaignLogRetry>
 */
class CampaignLogRetryFactory extends Factory
{
    protected $model = CampaignLogRetry::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_log_id' => CampaignLog::factory(),
            'chat_id' => null,
            'metadata' => null,
            'status' => 'ongoing',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Retry in ongoing state.
     */
    public function ongoing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ongoing',
        ]);
    }

    /**
     * Retry in success state.
     */
    public function success(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'metadata' => json_encode(['message_id' => 'wam_'.$this->faker->uuid()]),
        ]);
    }

    /**
     * Retry in failed state.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'metadata' => json_encode(['error' => 'Test retry error']),
        ]);
    }

    /**
     * Retry for a specific campaign log.
     */
    public function forCampaignLog(CampaignLog $log): static
    {
        return $this->state(fn (array $attributes) => [
            'campaign_log_id' => $log->id,
        ]);
    }
}
