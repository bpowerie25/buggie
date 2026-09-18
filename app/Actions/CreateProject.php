<?php

namespace App\Actions;

use App\Models\Project;
use App\Models\Status;
use App\Support\Billing\LimitExceeded;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProject
{
    /**
     * @param  array{name: string, key?: string|null, description?: string|null, site_url?: string|null}  $attributes
     */
    public function handle(array $attributes): Project
    {
        $workspace = app(Tenancy::class)->currentOrFail();

        if (! $workspace->isWithinLimit('projects')) {
            throw LimitExceeded::projects($workspace->plan()->limit('projects'));
        }

        return DB::transaction(function () use ($attributes) {
            $project = Project::create([
                'name' => $attributes['name'],
                'key' => ($attributes['key'] ?? null) ?: $this->suggestKey($attributes['name']),
                'slug' => $this->uniqueSlug($attributes['name']),
                'description' => $attributes['description'] ?? null,
                'site_url' => $attributes['site_url'] ?? null,
            ]);

            $this->seedStatuses($project);

            return $project->load('statuses');
        });
    }

    /** Every project starts with the six default statuses; names editable, categories not. */
    protected function seedStatuses(Project $project): void
    {
        foreach (Status::DEFAULTS as $position => $status) {
            $project->statuses()->create([
                ...$status,
                'position' => $position,
            ]);
        }
    }

    /** "Marketing Site" -> MAR; "GAA Website" -> GAAW. Falls back to a suffix on collision. */
    public function suggestKey(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $base = count($words) > 1
            ? strtoupper(collect($words)->take(4)->map(fn ($w) => $w[0])->implode(''))
            : strtoupper(substr($words[0] ?? 'PRJ', 0, 3));

        $base = substr(preg_replace('/[^A-Z0-9]/', '', $base) ?: 'PRJ', 0, 6);

        $key = $base;
        $suffix = 1;

        while (Project::withTrashed()->where('key', $key)->exists()) {
            $key = substr($base, 0, 5).$suffix;
            $suffix++;
        }

        return $key;
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 1;

        while (Project::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
