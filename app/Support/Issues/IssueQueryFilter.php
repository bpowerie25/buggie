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
        $this->version($query, $parsed);
        $this->customFields($query, $parsed, $viewer);
        $this->absence($query, $parsed);
        $this->overdue($query, $parsed);

        return $query;
    }

    /**
     * `is:overdue` — past its due date and still open.
     *
     * A value of `is` rather than an operator of its own, because it is the same kind
     * of thing `is:open` is: a statement about the issue's standing rather than about
     * one of its fields. `is` is single-valued, so `is:overdue` replaces `is:open`
     * and state() falls through to its default of open — which is what was meant. An
     * issue nobody is going to work on again is not late, it is finished.
     *
     * Strictly past: something due today has until the end of the day.
     */
    private function overdue(Builder $query, IssueQuery $parsed): void
    {
        if (! $parsed->has('is', 'overdue')) {
            return;
        }

        $query->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->startOfDay()->toDateString());
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

    /**
     * `field:key=value`, and `-field:key=value` for the negation.
     *
     * Prefixed rather than letting a custom field claim a bare key of its own: a
     * project is free to name a field "type" or "label", and a bare key would then
     * mean different things in different workspaces — or silently shadow the built-in
     * one, which is worse than being verbose.
     *
     * A client only ever filters on fields they are allowed to see. Without that, a
     * client could binary-search the value of an internal field by trying values and
     * watching the result count, which leaks it just as surely as printing it.
     */
    private function customFields(Builder $query, IssueQuery $parsed, User $viewer): void
    {
        foreach ([false, true] as $negated) {
            foreach ($parsed->all('field', negated: $negated) as $term) {
                if (! str_contains($term, '=')) {
                    // No value given: treat it as "this field is filled in at all".
                    $this->fieldPresence($query, $term, $viewer, $negated);

                    continue;
                }

                [$key, $value] = explode('=', $term, 2);

                $constraint = fn (Builder $q) => $q
                    ->whereHas('field', fn (Builder $f) => $this->fieldsVisibleTo($f, $viewer)
                        ->where('key', strtolower(trim($key))))
                    ->whereRaw('lower(value) = ?', [strtolower(trim($value))]);

                $negated
                    ? $query->whereDoesntHave('customFieldValues', $constraint)
                    : $query->whereHas('customFieldValues', $constraint);
            }
        }
    }

    private function fieldPresence(Builder $query, string $key, User $viewer, bool $negated): void
    {
        $constraint = fn (Builder $q) => $q
            ->whereHas('field', fn (Builder $f) => $this->fieldsVisibleTo($f, $viewer)
                ->where('key', strtolower(trim($key))))
            ->whereNotNull('value')
            ->where('value', '!=', '');

        $negated
            ? $query->whereDoesntHave('customFieldValues', $constraint)
            : $query->whereHas('customFieldValues', $constraint);
    }

    /**
     * @param  Builder<\App\Models\CustomField>  $query
     * @return Builder<\App\Models\CustomField>
     */
    private function fieldsVisibleTo(Builder $query, User $viewer): Builder
    {
        $workspace = app(\App\Support\Tenancy\Tenancy::class)->current();

        $staff = $workspace !== null
            && ($viewer->membershipIn($workspace)?->isStaff() ?? false);

        return $staff ? $query : $query->where('visible_to_client', true);
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

    /**
     * version:2.4.1 — by name, because that is what people say out loud.
     *
     * Names are unique per project rather than per workspace, so two projects may
     * each have a 2.4.1 and `version:2.4.1` matches both. Combining it with
     * `project:` narrows it, which is the same way every other operator here behaves.
     */
    private function version(Builder $query, IssueQuery $parsed): void
    {
        foreach ($parsed->all('version') as $name) {
            $query->whereHas('version', fn (Builder $q) => $q->where('name', $name));
        }

        foreach ($parsed->all('version', negated: true) as $name) {
            $query->whereDoesntHave('version', fn (Builder $q) => $q->where('name', $name));
        }
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
                // Everything not yet assigned to a release: the list you work from
                // when deciding what goes in the next one.
                'version' => $query->whereNull('version_id'),
                default => null,
            };
        }
    }
}
