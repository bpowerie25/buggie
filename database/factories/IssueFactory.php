<?php

namespace Database\Factories;

use App\Enums\IssuePriority;
use App\Enums\IssueType;
use App\Enums\IssueVisibility;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Issue> */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => rtrim(fake()->sentence(6), '.'),
            'description' => null,
            'description_text' => null,
            'type' => fake()->randomElement(IssueType::cases())->value,
            'priority' => fake()->randomElement(IssuePriority::cases())->value,
            'visibility' => IssueVisibility::Internal->value,
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    /**
     * Everything derived from the project is resolved here rather than in the
     * definition. State closures receive attributes that have not been expanded yet,
     * so `project_id` may still be a Factory instance at that point.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Issue $issue) {
            $project = $issue->project()->withoutGlobalScopes()->first();
            $number = $project->issue_sequence + 1;

            $project->forceFill(['issue_sequence' => $number])->save();

            $issue->forceFill([
                'workspace_id' => $project->workspace_id,
                'number' => $number,
                'key' => "{$project->key}-{$number}",
                'status_id' => $issue->status_id ?? $project->defaultStatus()?->id,
            ]);
        });
    }

    public function clientVisible(): static
    {
        return $this->state(fn () => ['visibility' => IssueVisibility::Client->value]);
    }

    /** Place the issue in this project's status for the given category. */
    public function inStatus(string $category): static
    {
        return $this->afterMaking(function (Issue $issue) use ($category) {
            $issue->status_id = $issue->project()->withoutGlobalScopes()->first()
                ->statuses()->where('category', $category)->value('id');
        });
    }
}
