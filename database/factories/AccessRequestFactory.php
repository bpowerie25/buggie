<?php

namespace Database\Factories;

use App\Enums\AccessRequestStatus;
use App\Models\AccessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessRequest>
 */
class AccessRequestFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'organisation' => null,
            'message' => fake()->sentence(),
            'status' => AccessRequestStatus::Pending->value,
        ];
    }

    /**
     * Asking for a workspace that does not exist yet. Use with createQuietly(), for the
     * reason AccessRequest::forOperators() gives.
     */
    public function central(): static
    {
        return $this->state(fn () => [
            'workspace_id' => null,
            'organisation' => fake()->company(),
        ]);
    }
}
