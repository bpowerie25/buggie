<?php

namespace Database\Factories;

use App\Models\Phase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Phase> */
class PhaseFactory extends Factory
{
    protected $model = Phase::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'position' => 0,
        ];
    }
}
