<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deleted issues, and the way back.
 *
 * Issues have been soft-deleted since the beginning and nothing has ever read them
 * again: no screen, no route, no `withTrashed` anywhere. The rows simply accumulated,
 * invisible and unreachable, which is the worst of both — it looks careful and behaves
 * like a permanent delete.
 *
 * Staff only, and only whoever can delete an issue in the first place. Undeleting is
 * the same authority as deleting, not a lesser one: somebody who cannot remove an
 * issue should not be able to bring a client's back either.
 */
class IssueTrashController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(Request $request): Response
    {
        $this->authorize('deleteAny', Issue::class);

        return Inertia::render('issues/trash', [
            'issues' => Issue::onlyTrashed()
                ->with(['project:id,key,name', 'status:id,name,color,category'])
                ->orderByDesc('deleted_at')
                ->limit(200)
                ->get()
                ->map(fn (Issue $issue) => [
                    'key' => $issue->key,
                    'title' => $issue->title,
                    'project' => $issue->project?->name,
                    'status' => $issue->status?->name,
                    'deleted_at' => $issue->deleted_at?->toIso8601String(),
                ]),
        ]);
    }

    public function restore(string $key): RedirectResponse
    {
        $this->authorize('deleteAny', Issue::class);

        // Scoped by the global scope, so a key from another workspace is simply not
        // found. onlyTrashed, so this cannot be used to "restore" a live issue as a
        // way of discovering that it exists.
        $issue = Issue::onlyTrashed()->where('key', $key)->firstOrFail();

        $issue->restore();

        return back()->with('success', "{$issue->key} is back.");
    }

    /**
     * Gone for good.
     *
     * Kept deliberately separate from the soft delete, and behind its own confirmation
     * in the interface: the whole point of the trash is that deleting twice is a
     * decision rather than a slip.
     */
    public function forceDelete(string $key): RedirectResponse
    {
        $this->authorize('deleteAny', Issue::class);

        $issue = Issue::onlyTrashed()->where('key', $key)->firstOrFail();
        $key = $issue->key;

        $issue->forceDelete();

        return back()->with('success', "{$key} has been deleted permanently.");
    }
}
