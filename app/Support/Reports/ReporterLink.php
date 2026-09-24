<?php

namespace App\Support\Reports;

use App\Enums\IssueEventType;
use App\Enums\ReporterIdentity;
use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Connecting a widget report's reporter to a client member of the project.
 *
 * Linking is only ever setting reporter_id. The client then sees the issue by the
 * rule that already exists — a client sees what they reported — and hears about it
 * the same way. Nothing here touches the visibility scope.
 *
 * Automatic only when the identity is verified, or when the workspace has chosen to
 * trust unverified addresses. Otherwise the name and address are kept on the issue
 * and staff can link by hand.
 */
final class ReporterLink
{
    public const TRUST_UNVERIFIED = 'trust_unverified_emails';

    public static function trustsUnverified(Workspace $workspace): bool
    {
        return (bool) ($workspace->settings[self::TRUST_UNVERIFIED] ?? false);
    }

    public static function qualifies(?ReporterIdentity $level, Workspace $workspace): bool
    {
        return match ($level) {
            ReporterIdentity::Verified => true,
            ReporterIdentity::Identified, ReporterIdentity::EmailUnverified => self::trustsUnverified($workspace),
            default => false,
        };
    }

    /** A client member holding the issue's project, with exactly this address. */
    public static function clientMatching(Issue $issue, ?string $email): ?User
    {
        if ($email === null || $email === '') {
            return null;
        }

        return self::eligible($issue)->whereRaw('lower(users.email) = ?', [strtolower($email)])->first();
    }

    /** Clients who could be linked: on the workspace as clients, holding the project. */
    public static function eligible(Issue $issue): BelongsToMany
    {
        return $issue->loadMissing('project')->project->clients()
            ->whereHas('workspaces', fn ($w) => $w
                ->where('workspaces.id', $issue->workspace_id)
                ->where('workspace_user.role', WorkspaceRole::Client->value));
    }

    /** On accepting a report: link if the identity and the address both qualify. */
    public static function linkIfTrusted(Issue $issue): ?User
    {
        if (! self::qualifies($issue->reporter_identity, $issue->workspace)) {
            return null;
        }

        $client = self::clientMatching($issue, $issue->reporter_email);

        if ($client !== null) {
            self::link($issue, $client, null, automatic: true);
        }

        return $client;
    }

    public static function link(Issue $issue, User $client, ?User $actor, bool $automatic = false): void
    {
        $issue->forceFill(['reporter_id' => $client->id])->save();
        $issue->watch($client, WatchReason::Reported);

        // Internal: who a report is attributed to is the team's record.
        $issue->recordEvent(IssueEventType::ReporterLinked, [
            'client' => $client->name,
            'automatic' => $automatic,
        ], $actor);
    }
}
