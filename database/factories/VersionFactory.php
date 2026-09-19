<?php

namespace Database\Factories;

use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Version> */
class VersionFactory extends Factory
{
    protected $model = Version::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->numerify('#.#.#'),
            'description' => null,
            'released_at' => null,
        ];
    }

    public function released(): self
    {
        return $this->state(fn () => ['released_at' => now()->subDays(3)]);
    }
}
