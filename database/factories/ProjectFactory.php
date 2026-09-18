<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            // Inside a bound workspace, belong to it; otherwise make one.
            'workspace_id' => fn () => app(Tenancy::class)->id() ?? Workspace::factory(),
            'name' => Str::title($name),
            'key' => Str::upper(Str::random(3)),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'description' => fake()->optional()->sentence(),
            'issue_sequence' => 0,
            'is_archived' => false,
            'settings' => [],
        ];
    }

    /** Real projects always have statuses; a factory-made one should too. */
    public function configure(): static
    {
        return $this->afterCreating(function (\App\Models\Project $project) {
            foreach (\App\Models\Status::DEFAULTS as $position => $status) {
                $project->statuses()->forceCreate([
                    ...$status,
                    'workspace_id' => $project->workspace_id,
                    'position' => $position,
                ]);
            }
        });
    }

    public function archived(): static
    {
        return $this->state(fn () => ['is_archived' => true]);
    }
}
