<?php

namespace App\Actions;

use App\Models\Project;
use App\Support\Billing\LimitExceeded;
use App\Support\Templates\ProjectTemplates;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProject
{
    /**
     * @param  array{name: string, key?: string|null, description?: string|null, site_url?: string|null, template?: string|null, source_project_id?: int|string|null}  $attributes
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

            $this->applySetup($project, $attributes);

            return $project->load('statuses');
        });
    }

    /**
     * Statuses, labels and custom fields, from a template or from another project.
     *
     * Inside the same transaction as the project row: a project with half a workflow
     * is worse than no project, because nothing about it says it is unfinished.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function applySetup(Project $project, array $attributes): void
    {
        $setup = app(ApplyProjectSetup::class);
        $sourceId = $attributes['source_project_id'] ?? null;

        if ($sourceId !== null && $sourceId !== '') {
            /*
             * findOrFail through the workspace scope, so an id belonging to another
             * workspace is not found rather than found and copied. The request
             * refuses it first — this is the second lock, and it is the one that
             * holds when something other than the form calls this.
             */
            $setup->copyFrom(Project::findOrFail($sourceId), $project);

            return;
        }

        // An unknown template key throws. Quietly falling back to the defaults would
        // hand somebody a project that is not the one they asked for, and the only
        // symptom would be a workflow they have to rebuild by hand.
        $setup->fromTemplate(
            $project,
            app(ProjectTemplates::class)->findOrFallback($attributes['template'] ?? null),
        );
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
