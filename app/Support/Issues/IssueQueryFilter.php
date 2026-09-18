<?php

namespace App\Support\Issues;

use App\Enums\IssuePriority;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a parsed IssueQuery into constraints on an Issue builder.
 *
 * Kept apart from the parser so the language stays a pure, testable value object and
 * this file holds every place the database is touched.
 */
class IssueQueryFilter
{
    /**
     * @param  Builder<Issue>  $query
     * @return Builder<Issue>
     */
    public function apply(Builder $query, IssueQuery $parsed, User $viewer): Builder
    {
        if ($parsed->text !== '') {
            $query->search($parsed->text);
        }

        match ($parsed->state()) {
            'open' => $query->open(),
            'closed' => $query->closed(),
            default => null,
        };

        $this->project($query, $parsed);
        $this->person($query, $parsed, $viewer, 'assignee');
        $this->person($query, $parsed, $viewer, 'reporter');
        $this->labels($query, $parsed);
        $this->type($query, $parsed);
        $this->priority($query, $parsed);
        $this->absence($query, $parsed);

        return $query;
    }

    private function project(Builder $query, IssueQuery $parsed): void
    {
        foreach ($parsed->all('project') as $slug) {
            $query->whereHas('project', fn (Builder $q) => $q->where('slug', $slug));
        }

        foreach ($parsed->all('project', negated: true) as $slug) {
            $query->whereDoesntHave('project', fn (Builder $q) => $q->where('slug', $slug));
        }
    }

    private function person(Builder $query, IssueQuery $parsed, User $viewer, string $key): void
    {
        $column = $key.'_id';

        foreach ([false, true] as $negated) {
            foreach ($parsed->all($key, $negated) as $value) {
                $id = $this->resolvePerson($value, $viewer);

                if ($value === 'none') {
                    $negated ? $query->whereNotNull($column) : $query->whereNull($column);

                    continue;
                }

                // An unresolvable name must match nothing, not everything.
                $negated
                    ? $query->where(fn (Builder $q) => $q->where($column, '!=', $id ?? 0)->orWhereNull($column))
                    : $query->where($column, $id ?? 0);
            }
        }
    }

    private function resolvePerson(string $value, User $viewer): ?int
    {
        if ($value === '@me' || $value === 'me') {
            return $viewer->id;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        // Match on name, so assignee:sam works without knowing ids.
        return User::query()
            ->whereRaw('lower(name) like ?', [strtolower($value).'%'])
            ->value('id');
    }

    private function labels(Builder $query, IssueQuery $parsed): void
    {
        foreach ($parsed->all('label') as $name) {
            $query->whereHas('labels', fn (Builder $q) => $q->where('name', $name));
        }

        foreach ($parsed->all('label', negated: true) as $name) {
            $query->whereDoesntHave('labels', fn (Builder $q) => $q->where('name', $name));
        }
    }

    private function type(Builder $query, IssueQuery $parsed): void
    {
        foreach ($parsed->all('type') as $type) {
            $query->where('type', $type);
        }

        foreach ($parsed->all('type', negated: true) as $type) {
            $query->where('type', '!=', $type);
        }
    }

    private function priority(Builder $query, IssueQuery $parsed): void
    {
        foreach ([false, true] as $negated) {
            foreach ($parsed->all('priority', $negated) as $name) {
                $value = $this->resolvePriority($name);

                if ($value === null) {
                    continue;
                }

                $negated
                    ? $query->where('priority', '!=', $value)
                    : $query->where('priority', $value);
            }
        }
    }

    private function resolvePriority(string $name): ?int
    {
        foreach (IssuePriority::cases() as $case) {
            if (strtolower($case->name) === strtolower($name)) {
                return $case->value;
            }
        }

        return is_numeric($name) ? (int) $name : null;
    }

    /** no:assignee, no:label, no:description — the "needs attention" filters. */
    private function absence(Builder $query, IssueQuery $parsed): void
    {
        foreach ($parsed->all('no') as $what) {
            match ($what) {
                'assignee' => $query->whereNull('assignee_id'),
                'label' => $query->whereDoesntHave('labels'),
                'description' => $query->where(fn (Builder $q) => $q
                    ->whereNull('description_text')
                    ->orWhere('description_text', '')),
                'priority' => $query->where('priority', IssuePriority::None->value),
                default => null,
            };
        }
    }
}
