<?php

namespace App\Support\Issues;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Who an issue can be assigned to: staff, and nobody else.
 *
 * The assignee is whose job the issue is. A client was once assignable, as a way of
 * asking them something, and that is how "Matrix assigned this to Brian Power" came
 * to describe a client's own issue — the assignee then said the agency's work was
 * the client's. Whose turn it is belongs in the status ("awaiting client"), which is
 * what Reply & await client does. The assignee never changes on its own.
 *
 * One definition, used by every request that accepts an assignee and by the actions
 * behind them, so the API, bulk edit, triage and import cannot disagree.
 */
final class Assignable
{
    /** @return array<int, string> */
    public static function roles(): array
    {
        return array_values(array_map(
            fn (WorkspaceRole $role) => $role->value,
            array_filter(WorkspaceRole::cases(), fn (WorkspaceRole $role) => $role->isStaff()),
        ));
    }

    /** For a form request: a staff member of the workspace being worked in. */
    public static function rule(?int $workspaceId): Exists
    {
        return (new Exists('workspace_user', 'user_id'))
            ->where('workspace_id', $workspaceId)
            ->whereIn('role', self::roles());
    }

    public static function isAssignable(User $user, Workspace $workspace): bool
    {
        return $user->membershipIn($workspace)?->isStaff() ?? false;
    }

    /** For the actions: refuse, as a validation error, anybody who is not staff. */
    public static function ensure(?User $user, Workspace $workspace, string $field = 'assignee_id'): void
    {
        if ($user !== null && ! self::isAssignable($user, $workspace)) {
            throw ValidationException::withMessages([
                $field => "{$user->name} is not on the team, so the issue cannot be assigned to them. To ask a client something, use Reply & await client.",
            ]);
        }
    }
}
