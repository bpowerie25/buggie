<?php

namespace App\Http\Controllers;

use App\Actions\ShiftDependents;
use App\Actions\UpdateIssue;
use App\Models\Issue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Moving an issue's dates from the timeline: drag a bar, stretch an end, drop an
 * undated issue onto a day.
 *
 * Refused when the issue has changed since the timeline was loaded. Two people
 * planning the same quarter would otherwise overwrite each other's dates without
 * either knowing — the reason dragging waited this long. The refusal says who, and
 * the timeline reloads showing what is there now.
 */
class IssueScheduleController extends Controller
{
    public function __invoke(Request $request, Issue $issue, UpdateIssue $action): RedirectResponse
    {
        $this->authorize('update', $issue);

        $validated = $request->validate([
            'start_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['present', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_on'],
            'version' => ['required', 'string', 'max:64'],
            // Move the work waiting on this later too, when this now ends after it
            // was meant to start. A choice on the timeline, off unless asked for.
            'shift_dependents' => ['sometimes', 'boolean'],
        ]);

        if (! hash_equals($issue->scheduleVersion(), $validated['version'])) {
            $who = $issue->events()->with('actor:id,name')->reorder('created_at', 'desc')->first()?->actor?->name;

            return back()->withErrors([
                'schedule' => ($who ? "{$who} changed {$issue->key}" : "{$issue->key} changed")
                    .' since you loaded the timeline, so your change was not saved. The timeline now shows it as it is.',
            ]);
        }

        $moved = DB::transaction(function () use ($action, $issue, $validated, $request) {
            $action->handle($issue, [
                'start_on' => $validated['start_on'],
                'due_on' => $validated['due_on'],
            ], $request->user());

            return ($validated['shift_dependents'] ?? false)
                ? app(ShiftDependents::class)->handle($issue, $request->user())
                : [];
        });

        if ($moved !== []) {
            $count = count($moved);

            return back()->with('success', "{$issue->key} moved, and {$count} issue".($count === 1 ? '' : 's')
                .' waiting on it moved later with it: '.implode(', ', $moved).'.');
        }

        return back();
    }
}
