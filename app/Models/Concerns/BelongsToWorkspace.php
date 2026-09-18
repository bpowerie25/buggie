<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\Tenancy\MissingWorkspaceContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Opt a model into workspace tenancy: every query is filtered to the current
 * workspace, and every insert is stamped with it.
 *
 * Models opt in explicitly. Nothing opts out silently — see TenancyIsolationTest,
 * which asserts every model with a workspace_id column uses this trait.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function ($model) {
            if ($model->workspace_id !== null) {
                return;
            }

            $tenancy = app(Tenancy::class);

            $model->workspace_id = $tenancy->id()
                ?? throw MissingWorkspaceContext::for($model::class);
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Deliberately query across every workspace. Name it so it shows up in review. */
    public function scopeAcrossAllWorkspaces(Builder $query): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::class);
    }
}
