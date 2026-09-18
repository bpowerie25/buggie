<?php

namespace App\Console\Commands;

use App\Models\PendingNotification;
use App\Models\Workspace;
use App\Notifications\IssueDigest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns batched activity into one message per person per issue.
 *
 * A group is sent once it has been quiet for the digest delay, so a burst of edits
 * arrives as a single email rather than one per change — and a long-running argument
 * in the comments does not hold its own notification hostage for ever, because the
 * window is measured from the *last* entry.
 */
class FlushNotifications extends Command
{
    protected $signature = 'notifications:flush';

    protected $description = 'Send batched issue notifications that have gone quiet';

    public function handle(Tenancy $tenancy): int
    {
        $cutoff = now()->subMinutes((int) config('buggy.digest_delay_minutes'));
        $sent = 0;

        // Group keys first, so one huge workspace cannot starve the others.
        $groups = PendingNotification::query()
            ->withoutGlobalScopes()
            ->select('workspace_id', 'user_id', 'issue_id')
            ->groupBy('workspace_id', 'user_id', 'issue_id')
            ->havingRaw('max(created_at) <= ?', [$cutoff])
            ->get();

        foreach ($groups as $group) {
            $workspace = Workspace::find($group->workspace_id);

            if ($workspace === null) {
                continue;
            }

            $sent += $tenancy->run($workspace, fn () => $this->send($group->user_id, $group->issue_id, $cutoff));
        }

        $this->info("Sent {$sent} digest".($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }

    private function send(int $userId, int $issueId, \DateTimeInterface $cutoff): int
    {
        return DB::transaction(function () use ($userId, $issueId, $cutoff) {
            $entries = PendingNotification::with(['actor:id,name', 'user', 'issue.project', 'issue.workspace'])
                ->where('user_id', $userId)
                ->where('issue_id', $issueId)
                ->where('created_at', '<=', $cutoff)
                // Locked so a concurrent flush cannot send the same digest twice.
                ->lockForUpdate()
                ->orderBy('created_at')
                ->get();

            if ($entries->isEmpty()) {
                return 0;
            }

            $user = $entries->first()->user;
            $issue = $entries->first()->issue;

            if ($user !== null && $issue !== null) {
                $user->notify(new IssueDigest($issue, $entries));
            }

            PendingNotification::whereIn('id', $entries->pluck('id'))->delete();

            return 1;
        });
    }
}
