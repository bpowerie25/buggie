<?php

namespace App\Support\Issues;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Whose name a client reads beside something the team did.
 *
 * Staff read everybody's name. A client reads the workspace's name for anything
 * staff wrote — "Matrix" — unless the workspace has chosen to show individual staff,
 * because an agency usually speaks to its clients as the agency. Clients' own names
 * are shown as they are, including to other clients who can see the same issue.
 */
final class AuthorLabel
{
    public const SHOW_STAFF_NAMES = 'show_staff_names_to_clients';

    public static function for(?User $author, Workspace $workspace, bool $readerIsStaff, ?string $fallback = null): string
    {
        if ($author === null) {
            return $fallback ?? $workspace->name;
        }

        if ($readerIsStaff || ! self::isStaff($author, $workspace) || self::showsStaffNames($workspace)) {
            return $author->name;
        }

        return $workspace->name;
    }

    /**
     * Staff or client. Nobody (a portal reporter) is the client side; somebody who has
     * since left the workspace is judged by not being a client, since only staff and
     * clients ever write here.
     */
    public static function role(?User $author, Workspace $workspace): string
    {
        return $author !== null && self::isStaff($author, $workspace) ? 'staff' : 'client';
    }

    public static function showsStaffNames(Workspace $workspace): bool
    {
        return (bool) ($workspace->settings[self::SHOW_STAFF_NAMES] ?? false);
    }

    private static function isStaff(User $user, Workspace $workspace): bool
    {
        return $user->membershipIn($workspace) !== WorkspaceRole::Client;
    }
}
