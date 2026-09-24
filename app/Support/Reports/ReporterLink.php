<?php

namespace App\Support\Reports;

use App\Enums\IssueEventType;
use App\Enums\ReporterIdentity;
use App\Enums\ReportState;
use App\Enums\WatchReason;
use App\Enums\WorkspaceRole;
use App\Models\Issue;
use App\Models\Report;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
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

    /**
     * Link the widget issues already in a workspace, by the same rule as accepting a
     * report now does.
     *
     * The link is made when a report is accepted, so turning on "trust unverified
     * emails" — or an install upgraded from before identities existed — leaves every
     * issue accepted earlier attributed to whoever triaged it, and the client who
     * reported it cannot see it. Issues accepted before the identity was recorded on
     * the issue get their name and address from the report they came from, as an
     * unverified email, which is all a typed address ever was.
     *
     * Never unlinks, never re-links an issue whose reporter is already a client, and
     * only ever links to a client who holds the issue's project. Safe to run twice.
     *
     * @return array{linked: int, examined: int, keys: array<int, string>}
     */
    public static function backfill(Workspace $workspace, bool $dryRun = false): array
    {
        return app(Tenancy::class)->run($workspace, function () use ($workspace, $dryRun) {
            $linked = [];
            $examined = 0;

            Issue::query()
                ->with(['project', 'reporter'])
                ->where(fn ($q) => $q
                    ->whereNotNull('reporter_identity')
                    // Accepted as the issue, not merged into one somebody else raised: a
                    // duplicate report must not make its sender the issue's reporter.
                    ->orWhereIn('id', Report::query()
                        ->where('state', ReportState::Promoted->value)
                        ->whereNotNull('issue_id')
                        ->select('issue_id')))
                ->orderBy('id')
                ->chunkById(200, function ($issues) use ($workspace, $dryRun, &$linked, &$examined) {
                    foreach ($issues as $issue) {
                        $examined++;

                        if ($issue->reporter !== null && ($issue->reporter->membershipIn($workspace) === WorkspaceRole::Client)) {
                            continue;
                        }

                        if ($issue->reporter_identity === null) {
                            $report = Report::query()
                                ->where('issue_id', $issue->id)
                                ->where('state', ReportState::Promoted->value)
                                ->oldest('id')
                                ->first();

                            if ($report === null || $report->reporter_email === null) {
                                continue;
                            }

                            $level = ReporterIdentity::tryFrom((string) $report->reporter_identity) ?? ReporterIdentity::EmailUnverified;

                            if (! $dryRun) {
                                $issue->forceFill([
                                    'reporter_identity' => $level->value,
                                    'reporter_name' => $report->reporter_name,
                                    'reporter_email' => $report->reporter_email,
                                ])->save();
                            } else {
                                $issue->reporter_identity = $level;
                                $issue->reporter_email = $report->reporter_email;
                            }
                        }

                        if (! self::qualifies($issue->reporter_identity, $workspace)) {
                            continue;
                        }

                        $client = self::clientMatching($issue, $issue->reporter_email);

                        if ($client === null) {
                            continue;
                        }

                        if (! $dryRun) {
                            self::link($issue, $client, null, automatic: true);
                        }

                        $linked[] = $issue->key;
                    }
                });

            return ['linked' => count($linked), 'examined' => $examined, 'keys' => $linked];
        });
    }
}
