<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\RunningTimer;
use App\Models\TimeEntry;
use App\Support\Time\Duration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Starting and stopping a clock.
 *
 * The optional half of time tracking. Everything here ends as an ordinary TimeEntry —
 * there is no second kind of logged time, and the Time report cannot tell which were
 * typed and which were timed, because by then it does not matter.
 */
class TimerController extends Controller
{
    public function start(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);
        $this->authorize('view', $issue);

        $user = $request->user();

        /*
         * Starting one stops the one already running, and logs it.
         *
         * Refusing would be annoying and silently discarding would lose real work.
         * Whatever was running was being worked on until this moment, so it is
         * written down and said out loud in the flash.
         */
        $previous = RunningTimer::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $stopped = $previous ? $this->finish($previous) : null;

        RunningTimer::create([
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'started_at' => now(),
            'billable' => $request->boolean('billable', true),
        ]);

        return back()->with('success', $stopped
            ? "Timer started on {$issue->key}. {$stopped} was logged against what you were on before."
            : "Timer started on {$issue->key}.");
    }

    public function stop(Request $request): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);

        $timer = $this->running($request);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'billable' => ['boolean'],
        ]);

        $timer->fill([
            'note' => $validated['note'] ?? $timer->note,
            'billable' => $request->boolean('billable', $timer->billable),
        ]);

        if ($timer->wasForgotten()) {
            // No guess. Nobody worked nineteen hours straight, and writing down that
            // they did is worse than asking them what they actually did.
            $key = $timer->issue?->key;
            $ran = Duration::format($timer->elapsedMinutes());

            $timer->delete();

            return back()->with(
                'error',
                "That timer had been running for {$ran}, so nothing was logged — "
                ."it was left on rather than worked. Add the time to {$key} by hand.",
            );
        }

        $logged = $this->finish($timer);

        return back()->with('success', "{$logged} logged.");
    }

    /** Stop without writing anything down. */
    public function discard(Request $request): RedirectResponse
    {
        $this->authorize('create', TimeEntry::class);

        $this->running($request)->delete();

        return back()->with('success', 'Timer discarded, nothing logged.');
    }

    /**
     * Turn a running timer into a logged entry and remove it.
     *
     * One transaction: a crash between writing the entry and clearing the timer would
     * leave a clock that has already been paid for still running.
     *
     * @return string what was logged, for the flash
     */
    private function finish(RunningTimer $timer): string
    {
        $minutes = max(1, $timer->elapsedMinutes());

        /*
         * Run inside the timer's own workspace, which is not always the one bound to
         * this request: starting a timer stops whatever was running, and that may
         * have been in a different workspace. `workspace_id` is never mass-assignable
         * — BelongsToWorkspace stamps it — so the tenant has to be right rather than
         * the attribute passed.
         */
        $workspace = \App\Models\Workspace::withoutGlobalScopes()->findOrFail($timer->workspace_id);

        return app(\App\Support\Tenancy\Tenancy::class)->run($workspace, fn () => DB::transaction(function () use ($timer, $minutes) {
            TimeEntry::create([
                'issue_id' => $timer->issue_id,
                'user_id' => $timer->user_id,
                'minutes' => $minutes,
                'spent_on' => $timer->started_at->toDateString(),
                'note' => $timer->note,
                'billable' => $timer->billable,
            ]);

            $timer->delete();

            return Duration::format($minutes);
        }));
    }

    private function running(Request $request): RunningTimer
    {
        $timer = RunningTimer::withoutGlobalScopes()
            ->where('user_id', $request->user()->id)
            ->with('issue:id,key')
            ->first();

        abort_if($timer === null, 404, 'No timer is running.');

        return $timer;
    }
}
