<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * A personal access token, pinned to one workspace.
 *
 * Deliberately not workspace-scoped by the global scope: the token is looked up
 * before any workspace has been resolved, so scoping it would make it unfindable.
 * The binding is enforced by the middleware instead, which compares the token's
 * workspace with the subdomain it arrived on.
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['workspace_id', 'name', 'token', 'abilities', 'expires_at'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
