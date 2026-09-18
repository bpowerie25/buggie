<?php

namespace App\Jobs;

use App\Enums\IssueEventType;
use App\Enums\ReportState;
use App\Models\Issue;
use App\Models\Report;
use App\Models\Workspace;
use App\Support\Reports\Fingerprint;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Fingerprints a freshly ingested report and groups it if it is a bug we already know
 * about. Anything it cannot group with confidence stays in the triage inbox for a human.
 */
class ProcessIncomingReport implements ShouldQueue
{
    use Queueable;

    /**
     * How long after closing an issue a new occurrence counts as the same bug rather
     * than a fresh regression. A month later is a different bug, and quietly reopening
     * a long-closed issue hides that.
     */
    public const REOPEN_WINDOW_DAYS = 14;

    public function __construct(
        public int $reportId,
        public int $workspaceId,
    ) {}

    public function handle(Tenancy $tenancy): void
    {
        $workspace = Workspace::find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $tenancy->run($workspace, function () {
            $report = Report::find($this->reportId);

            if ($report === null || $report->state !== ReportState::New) {
                return;
            }

            $fingerprint = Fingerprint::for(
                $report->error,
                $report->environment['url'] ?? null,
            );

            if ($fingerprint === null) {
                // No error to group on: it goes to a human, individually.
                return;
            }

            $report->forceFill(['fingerprint' => $fingerprint])->save();

            $issue = Issue::where('project_id', $report->project_id)
                ->where('fingerprint', $fingerprint)
                ->latest('last_seen_at')
                ->first();

            if ($issue === null) {
                return;
            }

            if (! $issue->isOpen() && $this->tooOldToReopen($issue)) {
                // Leave it in the inbox so a person decides whether this is a
                // regression worth linking rather than silently reviving old history.
                return;
            }

            $this->recordOccurrence($issue, $report);
        });
    }

    private function tooOldToReopen(Issue $issue): bool
    {
        return $issue->closed_at === null
            || $issue->closed_at->lt(now()->subDays(self::REOPEN_WINDOW_DAYS));
    }

    private function recordOccurrence(Issue $issue, Report $report): void
    {
        DB::transaction(function () use ($issue, $report) {
            $wasClosed = ! $issue->isOpen();

            $issue->forceFill([
                'occurrence_count' => $issue->occurrence_count + 1,
                'last_seen_at' => now(),
            ]);

            if ($wasClosed) {
                $reopened = $issue->project->statuses()
                    ->open()
                    ->orderBy('position')
                    ->first();

                if ($reopened) {
                    $issue->forceFill([
                        'status_id' => $reopened->id,
                        'closed_at' => null,
                        'resolved_at' => null,
                    ]);
                }
            }

            $issue->save();

            $issue->recordEvent(IssueEventType::Occurrence, [
                'report_id' => $report->id,
                'count' => $issue->occurrence_count,
                'url' => $report->environment['url'] ?? null,
            ]);

            if ($wasClosed) {
                $issue->recordEvent(IssueEventType::Reopened, [
                    'reason' => 'occurrence',
                    'report_id' => $report->id,
                ]);
            }

            $report->forceFill([
                'state' => ReportState::Merged,
                'issue_id' => $issue->id,
                'triaged_at' => now(),
            ])->save();
        });
    }
}
