<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\WidgetKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WidgetKey> */
class WidgetKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'public_key' => WidgetKey::generateKey(),
            'allowed_origins' => [],
            'mode' => 'identified',
            'require_email' => false,
            'capture_screenshot' => true,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (WidgetKey $key) {
            $project = $key->project()->withoutGlobalScopes()->first();
            $key->forceFill(['workspace_id' => $project->workspace_id]);
        });
    }

    public function restrictedTo(array $origins): static
    {
        return $this->state(fn () => ['allowed_origins' => $origins]);
    }
}
