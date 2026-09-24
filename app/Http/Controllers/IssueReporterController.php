<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Support\Reports\ReporterLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Link to client": attribute a widget report to a client member by hand.
 *
 * For the reports that were not linked automatically — an unverified address on a
 * workspace that does not trust them, or one that matched nobody. Staff decide, and
 * only a client who holds the issue's project can be chosen.
 */
class IssueReporterController extends Controller
{
    public function __invoke(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $client = ReporterLink::eligible($issue)->whereKey($validated['user_id'])->first();

        if ($client === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Only a client who can see this project can be linked as its reporter.',
            ]);
        }

        ReporterLink::link($issue, $client, $request->user());

        return back()->with('success', "Linked to {$client->name}, who can now see this issue.");
    }
}
