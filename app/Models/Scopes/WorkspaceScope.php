<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\MissingWorkspaceContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            $builder->where(
                $model->qualifyColumn('workspace_id'),
                $tenancy->id(),
            );

            return;
        }

        if ($tenancy->isStrict()) {
            throw MissingWorkspaceContext::for($model::class);
        }
    }
}
