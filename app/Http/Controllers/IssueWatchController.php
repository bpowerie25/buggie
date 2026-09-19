<?php

namespace App\Http\Controllers;

use App\Enums\WatchReason;
use App\Models\Issue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Following an issue, or not.
 *
 * Authorised by `view` rather than `update`: a client following their own bug is not
 * editing it, and asking to be told when something moves is not a privilege.
 */
class IssueWatchController extends Controller
{
    public function store(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('view', $issue);

        $issue->watch($request->user(), WatchReason::Manual);

        return back();
    }

    public function destroy(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('view', $issue);

        $issue->unwatch($request->user());

        return back();
    }
}
