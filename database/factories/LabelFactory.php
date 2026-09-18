<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Label> */
class LabelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => fn () => app(Tenancy::class)->id() ?? Workspace::factory(),
            'name' => fake()->unique()->word(),
            'color' => fake()->hexColor(),
            'description' => null,
        ];
    }
}
