<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\TimeEntry;
use App\Support\Time\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TimeEntryController extends Controller
{
    public function store(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);

        // Logging time against an issue you cannot see would be a way to find out it
        // exists, and to put hours somewhere nobody expects to find them.
        $this->authorize('view', $issue);

        $validated = $request->validate([
            'duration' => ['required', 'string', 'max:20'],
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            'billable' => ['boolean'],
        ]);

        try {
            $minutes = Duration::parse($validated['duration']);
        } catch (InvalidArgumentException $e) {
            // The parser's own words. "Invalid duration" tells nobody whether the
            // problem was the format or the length.
            throw ValidationException::withMessages(['duration' => $e->getMessage()]);
        }

        TimeEntry::create([
            'issue_id' => $issue->id,
            'user_id' => $request->user()->id,
            'minutes' => $minutes,
            'spent_on' => $validated['spent_on'],
            'note' => $validated['note'] ?? null,
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', Duration::format($minutes).' logged against '.$issue->key.'.');
    }

    public function destroy(TimeEntry $entry): RedirectResponse
    {
        $this->authorize('delete', $entry);

        $entry->delete();

        return back()->with('success', 'Time entry removed.');
    }

    /** The estimate lives on the issue, not on an entry, but belongs to this screen. */
    public function estimate(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);
        $this->authorize('update', $issue);

        $validated = $request->validate([
            'estimate' => ['nullable', 'string', 'max:20'],
        ]);

        $given = $validated['estimate'] ?? null;

        if ($given === null || trim($given) === '') {
            // Cleared, not zeroed. "Nobody estimated this" and "this will take no
            // time" are different claims.
            $issue->forceFill(['estimate_minutes' => null])->save();

            return back()->with('success', 'Estimate cleared.');
        }

        try {
            $minutes = Duration::parse($given);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['estimate' => $e->getMessage()]);
        }

        $issue->forceFill(['estimate_minutes' => $minutes])->save();

        return back()->with('success', 'Estimate set to '.Duration::format($minutes).'.');
    }
}
