<?php

namespace App\Http\Controllers;

use App\Enums\NotificationReason;
use App\Models\InAppNotification;
use App\Support\Notifications\ActivitySentence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What has happened to you, as a list you can actually read.
 *
 * Notifications were email and nothing else, which on an install with no mail
 * configured meant they were nothing at all: the activity was recorded, the digest
 * was assembled, and it went into a socket that was never connected. The settings
 * screen called "Notifications" set preferences about messages nobody received.
 *
 * ## Visibility is decided here, not when the row was written
 *
 * Every row is put through IssuePolicy before it is rendered, the same way
 * ChaseDueIssues does before it records one. A notification is written at the moment
 * something happens and read whenever somebody logs in, and in between a person can
 * be removed from the workspace, lose a project grant, be demoted from staff to
 * client, or watch an issue stop being shared with clients. Trusting the write-time
 * check would leak all four.
 *
 * The list is therefore fetched with `addressedTo`, which knows nothing about issue
 * visibility, and filtered by the policy itself. `InAppNotification::visibleTo` —
 * the same rules in SQL — exists only for the unread badge, which runs on every page
 * load and cannot afford a policy call per row. Deliberately not used here: a SQL
 * prefilter that agrees with the policy would hide a broken policy call, and one
 * that disagreed would be the leak.
 */
class NotificationController extends Controller
{
    /**
     * Newest hundred, no paging.
     *
     * A notification list is read from the top and abandoned a screen in; the second
     * page of one is somewhere nobody has ever been. Older than this is still on the
     * issue, which is where the history actually lives.
     *
     * The cut happens before the policy runs, so somebody who has just lost access
     * to a project can see fewer than a hundred. Refilling the page would mean
     * looping the query until it came up full, which is a way to turn one bounded
     * query into an unbounded number of them.
     */
    private const LIMIT = 100;

    public function index(Request $request): Response
    {
        $user = $request->user();

        $entries = InAppNotification::query()
            ->addressedTo($user)
            // Eager loaded because strict mode turns a lazy load into an exception,
            // and every one of these is read below: the policy wants the issue, the
            // list shows its project, and the sentence names the actor.
            ->with(['issue.project:id,name,slug', 'actor:id,name'])
            ->orderByDesc('created_at')
            // Activity is timestamped to the microsecond but a due-date sweep writes
            // a whole batch inside one transaction; id breaks the tie so the order
            // is stable between two loads of the same page.
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (InAppNotification $entry) => $entry->issue !== null
                && Gate::forUser($user)->allows('view', $entry->issue))
            ->values();

        return Inertia::render('notifications/index', [
            'notifications' => $this->serialise($entries),
            'unread' => $entries->whereNull('read_at')->count(),
            'limit' => self::LIMIT,
        ]);
    }

    /**
     * Opening one: marked read, then the browser is sent to the issue.
     *
     * A POST rather than a link with a side effect. A GET that changes something is
     * a GET a link prefetcher, a scanner or a mail client will fire on somebody's
     * behalf, and a notification list that quietly empties itself because something
     * crawled it is worse than one that never marked anything.
     */
    public function read(Request $request, InAppNotification $notification): RedirectResponse
    {
        $this->authoriseOwnership($request, $notification);

        $notification->markRead();

        return redirect()->to('/issues/'.$notification->issue->key);
    }

    /** The "I have looked at all of that" button. */
    public function readAll(Request $request): RedirectResponse
    {
        // Scoped to what they can see, so that clearing the badge clears exactly the
        // thing the badge was counting. Rows about an issue they have lost access to
        // stay unread, and come back if access does.
        InAppNotification::query()
            ->visibleTo($request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Somebody else's notification, or one about an issue this person may not see,
     * is NOT FOUND rather than forbidden — and not only for the usual reason. The
     * redirect target is an issue key, so a 403 here would hand over both the fact
     * that the issue exists and its number.
     */
    private function authoriseOwnership(Request $request, InAppNotification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        abort_if($notification->issue === null, 404);

        Gate::forUser($request->user())->authorize('view', $notification->issue);
    }

    /**
     * @param  Collection<int, InAppNotification>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function serialise(Collection $entries): array
    {
        return $entries->map(function (InAppNotification $entry): array {
            $reason = NotificationReason::from($entry->reason);

            return [
                'id' => $entry->id,
                'reason' => $reason->value,
                'label' => $reason->label(),
                'sentence' => ActivitySentence::for($reason, $entry->actor?->name, $entry->data ?? []),
                'issue' => [
                    'key' => $entry->issue->key,
                    'title' => $entry->issue->title,
                    'project' => $entry->issue->project?->name,
                ],
                'url' => '/issues/'.$entry->issue->key,
                'created_at' => $entry->created_at->toIso8601String(),
                'read' => $entry->read_at !== null,
            ];
        })->all();
    }
}
