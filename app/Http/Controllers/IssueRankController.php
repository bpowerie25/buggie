<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Support\Issues\BoardRank;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Where a card was dropped.
 *
 * The request names the two cards it landed between, not a rank. The client knows
 * what it can see; the server knows what is actually there. If somebody else has
 * dragged the same column in the meantime, a client-computed rank would be resolved
 * against a board that no longer exists — naming the neighbours means the worst case
 * is landing beside the card you aimed at rather than somewhere arbitrary.
 */
class IssueRankController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function __invoke(Request $request, Issue $issue): RedirectResponse
    {
        $this->authorize('update', $issue);

        /*
         * Neighbours are scoped to this workspace by hand, because validation rules
         * do not see the global scope.
         *
         * An unscoped `exists:issues,key` accepts a key from somebody else's
         * workspace. It would then resolve to no rank at all and be read as "no
         * neighbour", so the card would land somewhere nobody asked for — and the
         * difference between a rejected key and an accepted one would say whether
         * that key exists elsewhere on the install. Refusing it is both more honest
         * and quieter.
         */
        $scoped = fn () => Rule::exists('issues', 'key')
            ->where('workspace_id', $this->tenancy->currentOrFail()->id);

        $validated = $request->validate([
            // Null at either end is the top or bottom of the column, which is a real
            // destination rather than a missing one.
            'after' => ['nullable', 'string', $scoped()],
            'before' => ['nullable', 'string', $scoped()],
        ]);

        $above = $this->rankOf($validated['after'] ?? null);
        $below = $this->rankOf($validated['before'] ?? null);

        $rank = BoardRank::between($above, $below);

        if ($rank === null) {
            // The gap ran out, or the neighbours arrived the wrong way round. Space
            // the board out and ask again with ranks that have somewhere to go.
            DB::transaction(function () use (&$rank, $validated) {
                BoardRank::renumber($this->tenancy->currentOrFail()->id);

                $rank = BoardRank::between(
                    $this->rankOf($validated['after'] ?? null),
                    $this->rankOf($validated['before'] ?? null),
                );
            });
        }

        abort_if($rank === null, 409, 'The board moved underneath that drag. Try again.');

        // forceFill: board_rank is not mass-assignable, and this is bookkeeping about
        // where a card sits rather than a change to the issue, so it records no
        // activity event and no webhook.
        $issue->forceFill(['board_rank' => $rank])->save();

        return back();
    }

    /** The rank of a neighbour, scoped — a key from another workspace is not a neighbour. */
    private function rankOf(?string $key): ?float
    {
        if ($key === null) {
            return null;
        }

        $rank = Issue::where('key', $key)->value('board_rank');

        return $rank === null ? null : (float) $rank;
    }
}
