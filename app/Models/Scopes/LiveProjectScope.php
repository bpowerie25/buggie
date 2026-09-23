<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Issues belonging to a deleted project are not issues anybody wants to see.
 *
 * Deleting a project soft-deletes it, and the `cascadeOnDelete` on `issues.project_id`
 * is a database-level rule that only fires on a real DELETE — so the issues stayed
 * exactly where they were, in every list, belonging to a project that no longer
 * resolves. `$issue->project` then returned null and the issue list crashed for the
 * whole workspace, not just for that project. One click, and the tracker was unusable
 * with no way back, because projects have no restore path either.
 *
 * A scope rather than cascading the soft delete onto every issue: it is reversible the
 * moment a project can be restored, it fixes rows that are already orphaned, and it
 * does not put a thousand issues into a trash meant for the handful somebody deleted
 * deliberately.
 *
 * The cost is an EXISTS subquery on every issue query, which is the right trade for a
 * rule that has to hold in the list, the board, the export, the API and every count.
 */
class LiveProjectScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // whereHas rather than a join: the relation already carries Project's own
        // soft-delete scope, so "has a project" means "has one that is not deleted"
        // without this file needing to know how that is expressed.
        $builder->whereHas('project');
    }
}
