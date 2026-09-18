<?php

namespace Database\Factories;

use App\Enums\ReportState;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => rtrim(fake()->sentence(6), '.'),
            'body' => fake()->optional()->paragraph(),
            'reporter_name' => fake()->name(),
            'reporter_email' => fake()->safeEmail(),
            'environment' => ['url' => 'https://acme.test/checkout'],
            'console' => [],
            'network' => [],
            'state' => ReportState::New->value,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Report $report) {
            $project = $report->project()->withoutGlobalScopes()->first();
            $report->forceFill(['workspace_id' => $project->workspace_id]);
        });
    }

    public function withError(string $message = 'Cannot read properties of null'): static
    {
        return $this->state(fn () => [
            'error' => [
                'message' => $message,
                'stack' => "TypeError\n  at pay (https://acme.test/assets/checkout.js:12:44)",
            ],
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn () => ['reporter_name' => null, 'reporter_email' => null]);
    }
}
