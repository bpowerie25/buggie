<?php

namespace App\Console\Commands;

use App\Enums\ReportState;
use App\Models\InAppNotification;
use App\Models\PortalToken;
use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Ages out the personal data that bug reports collect as a side effect of being
 * useful — screenshots of whatever was on someone's screen, the address they wrote
 * from, the account they were signed in as.
 *
 * Issues and comments are never touched: they are the work product, and a tracker
 * that deletes its own history is not a tracker. What goes is the raw intake around
 * them.
 *
 * Runs across every workspace, so it deliberately bypasses the tenancy scope.
 */
class PruneOldData extends Command
{
    protected $signature = 'buggie:prune {--dry-run : Report what would go without deleting anything}';

    protected $description = 'Delete aged screenshots, reporter identities and dismissed reports';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $results = [
            'screenshots deleted' => $this->pruneScreenshots($dry),
            'reporter identities scrubbed' => $this->scrubReporters($dry),
            'dismissed reports deleted' => $this->deleteDismissed($dry),
            'expired portal links deleted' => $this->deleteExpiredTokens($dry),
            'old notifications deleted' => $this->deleteOldNotifications($dry),
        ];

        foreach ($results as $label => $count) {
            $this->line(sprintf('%-32s %d', $label, $count));
        }

        if ($dry) {
            $this->comment('Dry run — nothing was deleted.');
        }

        return self::SUCCESS;
    }

    private function pruneScreenshots(bool $dry): int
    {
        $days = config('buggie.retention.screenshots');

        if (! $days) {
            return 0;
        }

        $disk = Storage::disk('local');
        $count = 0;

        Report::withoutGlobalScopes()
            ->whereNotNull('screenshot_path')
            ->where('created_at', '<', now()->subDays($days))
            ->chunkById(200, function ($reports) use ($disk, $dry, &$count) {
                foreach ($reports as $report) {
                    if (! $dry) {
                        // Missing files are fine: the row is the record we are clearing.
                        $disk->delete($report->screenshot_path);
                        $report->forceFill(['screenshot_path' => null])->saveQuietly();
                    }

                    $count++;
                }
            });

        return $count;
    }

    private function scrubReporters(bool $dry): int
    {
        $days = config('buggie.retention.reporter_identity');

        if (! $days) {
            return 0;
        }

        $query = Report::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($days))
            ->where(fn ($q) => $q
                ->whereNotNull('reporter_email')
                ->orWhereNotNull('reporter_name')
                ->orWhereNotNull('reporter_ref')
                ->orWhereNotNull('ip_hash'));

        if ($dry) {
            return $query->count();
        }

        $count = 0;

        $query->chunkById(200, function ($reports) use (&$count) {
            foreach ($reports as $report) {
                $environment = $report->environment ?? [];
                unset($environment['identity']);

                $report->forceFill([
                    'reporter_email' => null,
                    'reporter_name' => null,
                    'reporter_ref' => null,
                    'ip_hash' => null,
                    'environment' => $environment,
                ])->saveQuietly();

                $count++;
            }
        });

        return $count;
    }

    private function deleteDismissed(bool $dry): int
    {
        $days = config('buggie.retention.dismissed_reports');

        if (! $days) {
            return 0;
        }

        $query = Report::withoutGlobalScopes()
            ->whereIn('state', [ReportState::Spam->value, ReportState::Discarded->value])
            ->where('triaged_at', '<', now()->subDays($days));

        if ($dry) {
            return $query->count();
        }

        $count = 0;
        $disk = Storage::disk('local');

        $query->chunkById(200, function ($reports) use ($disk, &$count) {
            foreach ($reports as $report) {
                if ($report->screenshot_path) {
                    $disk->delete($report->screenshot_path);
                }

                $report->delete();
                $count++;
            }
        });

        return $count;
    }

    /**
     * In-app notifications, read or unread alike.
     *
     * Unread is not the same as unfinished. A notification nobody opened in three
     * months is not waiting to be opened, and keeping it would mean the badge on a
     * returning account counts a year of things that no longer matter. What actually
     * happened is still on the issue, which is never pruned.
     */
    private function deleteOldNotifications(bool $dry): int
    {
        $days = config('buggie.retention.notifications');

        if (! $days) {
            return 0;
        }

        $query = InAppNotification::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($days));

        return $dry ? $query->count() : $query->delete();
    }

    private function deleteExpiredTokens(bool $dry): int
    {
        $days = config('buggie.retention.expired_portal_tokens');

        if (! $days) {
            return 0;
        }

        $query = PortalToken::withoutGlobalScopes()
            ->where('expires_at', '<', now()->subDays($days));

        return $dry ? $query->count() : $query->delete();
    }
}
