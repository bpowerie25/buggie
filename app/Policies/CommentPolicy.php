<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Comment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

class CommentPolicy
{
    public function __construct(private Tenancy $tenancy) {}

    public function update(User $user, Comment $comment): bool
    {
        // Authors edit their own words. Nobody edits someone else's.
        return $comment->user_id === $user->id;
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id
            || ($this->role($user)?->canManageWorkspace() ?? false);
    }

    private function role(User $user): ?WorkspaceRole
    {
        $workspace = $this->tenancy->current();

        return $workspace ? $user->membershipIn($workspace) : null;
    }
}
