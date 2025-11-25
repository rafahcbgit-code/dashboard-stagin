<?php

namespace Database\Factories;

use App\Models\Chat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Chat>
 */
class ChatFactory extends Factory
{
    protected $model = Chat::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'organization_id' => 1,
            'wam_id' => 'wamid_test_'.Str::random(20),
            'contact_id' => 1,
            'user_id' => null,
            'type' => 'inbound',
            'metadata' => json_encode([
                'type' => 'text',
                'text' => ['body' => $this->faker->sentence()],
            ]),
            'media_id' => null,
            'status' => 'received',
            'is_read' => 0,
            'created_at' => now(),
        ];
    }

    /**
     * Chat with media attached.
     */
    public function withMedia(int $mediaId): static
    {
        return $this->state(fn (array $attributes) => [
            'media_id' => $mediaId,
        ]);
    }

    /**
     * Outbound chat (sent by system/user).
     */
    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'outbound',
            'status' => 'sent',
        ]);
    }
}
