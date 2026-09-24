<?php

namespace App\Console\Commands;

use App\Actions\UpdateIssue;
use App\Enums\IssueEventType;
use App\Enums\NotificationReason;
use App\Enums\StatusCategory;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Status;
use App\Support\Issues\ClientAudienceSummary;
use App\Support\Notifications\Notifier;
use App\Support\RichText\TiptapDocument;
use App\Support\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issues waiting on a client: remind them once, and close what has waited too long.
 *
 * Both are per project and off unless a day count is set. Safe to run as often as
 * the scheduler likes: the reminder is claimed with a conditional update before it
 * is sent, so two overlapping runs cannot both send it, and closing clears the wait,
 * so nothing is closed twice. A client replying to a closed issue reopens it — see
 * ClientConversation::clientReplied().
 */
class ChaseClients extends Command
{
    protected $signature = 'issues:chase-clients';

    protected $description = 'Remind clients who owe a reply, and close issues that waited too long (per project, off by default)';

    public function handle(Tenancy $tenancy, Notifier $notifier, ClientAudienceSummary $audience, UpdateIssue $updates): int
    {
        $reminded = 0;
        $closed = 0;

        // Across every workspace on purpose; each issue is then handled inside its own.
        Issue::query()->acrossAllWorkspaces()
            ->whereNotNull('awaiting_client_since')
            ->whereHas('status', fn ($q) => $q->where('is_awaiting_client', true))
            ->with(['project', 'workspace'])
            ->orderBy('id')
            ->chunkById(200, function ($issues) use ($tenancy, $notifier, $audience, $updates, &$reminded, &$closed) {
                foreach ($issues as $issue) {
                    $tenancy->run($issue->workspace, function () use ($issue, $notifier, $audience, $updates, &$reminded, &$closed) {
                        $project = $issue->project;
                        $closeAfter = $project->clientWaitDays('awaiting_close_days');
                        $remindAfter = $project->clientWaitDays('awaiting_reminder_days');

                        if ($closeAfter !== null && $issue->awaiting_client_since->lte(now()->subDays($closeAfter))) {
                            $closed += (int) $this->close($issue, $project, $closeAfter, $updates);

                            return;
                        }

                        if ($remindAfter !== null && $issue->awaiting_client_since->lte(now()->subDays($remindAfter))) {
                            $reminded += (int) $this->remind($issue, $notifier, $audience);
                        }
                    });
                }
            });

        $this->line("Reminded {$reminded}, closed {$closed}.");

        return self::SUCCESS;
    }

    private function remind(Issue $issue, Notifier $notifier, ClientAudienceSummary $audience): bool
    {
        // Claimed before it is sent. Whichever run gets the row sends; any other
        // finds it already taken and sends nothing.
        $claimed = DB::table('issues')
            ->where('id', $issue->id)
            ->whereNull('client_reminded_at')
            ->whereNotNull('awaiting_client_since')
            ->update(['client_reminded_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $since = $issue->awaiting_client_since->toFormattedDateString();

        foreach ($audience->visibleTo($issue) as $client) {
            $notifier->record($client, $issue, NotificationReason::ClientReminder, null, ['since' => $since]);
        }

        $issue->recordEvent(IssueEventType::ClientReminded, ['since' => $since]);

        return true;
    }

    private function close(Issue $issue, Project $project, int $days, UpdateIssue $updates): bool
    {
        return DB::transaction(function () use ($issue, $project, $days, $updates) {
            $locked = Issue::query()->lockForUpdate()->find($issue->id);

            if ($locked === null || $locked->awaiting_client_since === null) {
                return false;
            }

            $target = $this->closedStatus($project);

            if ($target === null) {
                return false;
            }

            // Said in the thread, in public, so the client reading it later knows
            // why it closed and that answering will reopen it.
            $text = "Closed after {$days} days without a reply. Reply here and it will reopen.";
            $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];

            $locked->comments()->create([
                'user_id' => null,
                'body' => $body,
                'body_text' => TiptapDocument::toPlainText($body),
                'is_internal' => false,
                'source' => 'system',
            ]);

            // status_before_waiting_id is kept: a late reply goes back there.
            $locked->awaiting_client_since = null;
            $locked->client_reminded_at = null;
            $locked->auto_closed_at = now();

            $updates->moveTo($locked, $target, null, IssueEventType::AutoClosed, ['days' => $days]);

            return true;
        });
    }

    /**
     * Closed, not resolved: nobody confirmed anything, so the first canceled status.
     * A workflow with none falls back to done rather than leaving the issue open.
     */
    private function closedStatus(Project $project): ?Status
    {
        return $project->statuses()->where('category', StatusCategory::Canceled->value)->orderBy('position')->first()
            ?? $project->statuses()->where('category', StatusCategory::Done->value)->orderBy('position')->first();
    }
}
