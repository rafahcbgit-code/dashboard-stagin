<?php

namespace Database\Factories;

use App\Models\Addon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Addon>
 */
class AddonFactory extends Factory
{
    protected $model = Addon::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category' => 'testing',
            'name' => $this->faker->unique()->words(2, true),
            'logo' => 'test.png',
            'description' => $this->faker->sentence(),
            'metadata' => null,
            'license' => 'regular',
            'version' => '1.0',
            'is_plan_restricted' => 0,
            'update_available' => 0,
            'status' => 1,
            'is_active' => 0,
        ];
    }

    /**
     * Google Authenticator addon state for TFA testing.
     */
    public function googleAuthenticator(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Google Authenticator',
            'category' => 'two factor authentication',
            'logo' => 'google_authenticator.png',
            'description' => 'Google Authenticator enhances security with two-factor authentication.',
            'is_active' => 0,
        ]);
    }

    /**
     * Google Recaptcha addon state.
     */
    public function googleRecaptcha(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Google Recaptcha',
            'category' => 'security',
            'is_active' => 0,
        ]);
    }

    /**
     * Set addon as active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => 1,
        ]);
    }

    /**
     * Set addon as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => 0,
        ]);
    }
}
