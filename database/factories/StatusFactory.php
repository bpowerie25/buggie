<?php

namespace Database\Factories;

use App\Enums\StatusCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Status> */
class StatusFactory extends Factory
{
    public function definition(): array
    {
        $project = Project::factory();

        return [
            'workspace_id' => fn (array $attrs) => Project::find($attrs['project_id'])?->workspace_id,
            'project_id' => $project,
            'name' => fake()->word(),
            'category' => fake()->randomElement(StatusCategory::cases())->value,
            'color' => fake()->hexColor(),
            'position' => 0,
            'is_default' => false,
        ];
    }
}
