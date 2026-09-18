<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a workspace-owned model is queried in strict mode without a resolved
 * workspace. This is deliberately loud: the alternative is a query that quietly
 * returns every tenant's rows.
 */
class MissingWorkspaceContext extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self(
            "Tried to query [{$model}] with no workspace resolved. Either run inside "
            .'Tenancy::run($workspace, ...) or opt out explicitly with '
            .'Model::query()->withoutGlobalScope(WorkspaceScope::class).'
        );
    }
}
