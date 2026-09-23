<?php

namespace App\Enums;

/**
 * What somebody outside the team may see of one project.
 *
 * Staff — owner, admin, member — reach every project in the workspace and have no row
 * in `project_user` at all, so nothing here applies to them. These are the tiers a
 * client can be granted, and they exist because "our client" is not one kind of
 * person: it is usually whoever reported the bug, and occasionally a project manager
 * at a larger organisation whose job is to see all of it.
 */
enum ProjectRole: string
{
    /*
     * Maintainer and Contributor predate this and are written by nothing. They are
     * kept because the column stores free text and an old row could still hold one;
     * treating an unknown value as the most restrictive tier is safer than failing to
     * parse it. Neither grants anything beyond Client today.
     */
    case Maintainer = 'maintainer';
    case Contributor = 'contributor';

    /**
     * The default, and the right one for almost everybody.
     *
     * Sees the issues they are part of: the ones they reported, and the ones they
     * were drawn into by commenting or being mentioned. Not the rest of the project.
     * A client of an agency usually has no business seeing what another department
     * reported, and the previous behaviour — every client-visible issue in the
     * project — quietly assumed otherwise.
     */
    case Client = 'client';

    /**
     * A project manager on the client side.
     *
     * Sees every client-visible issue in the project, which is what the Client tier
     * used to mean for everybody. Deliberately a grant rather than a default: it is
     * the tier you choose for one person at a larger organisation, not the tier
     * everybody lands in.
     */
    case ClientManager = 'client_manager';

    public function label(): string
    {
        return match ($this) {
            self::ClientManager => 'Client manager',
            default => ucfirst($this->value),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ClientManager => 'Sees every issue shared with the client on this project.',
            default => 'Sees only the issues they reported or were brought into.',
        };
    }

    /** Whether this tier sees the whole client-visible project rather than their own part of it. */
    public function seesEveryClientIssue(): bool
    {
        return $this === self::ClientManager;
    }

    /**
     * The tiers offered when granting somebody access.
     *
     * Maintainer and Contributor are absent on purpose: they mean nothing, and
     * offering a choice that does nothing is worse than not offering it.
     *
     * @return array<int, array<string, string>>
     */
    public static function grantable(): array
    {
        return array_map(
            fn (self $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ],
            [self::Client, self::ClientManager],
        );
    }
}
