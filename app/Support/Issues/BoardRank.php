<?php

namespace App\Support\Issues;

use App\Models\Issue;
use Illuminate\Support\Facades\DB;

/**
 * Where a card sits on the board.
 *
 * A float between its neighbours, so dropping a card is one UPDATE of one row rather
 * than renumbering everything below it. Integer positions are simpler to read and
 * wrong for the thing people do most on this screen.
 *
 * The cost of floats is that halving a gap repeatedly runs out of precision. A double
 * holds about fifteen significant digits, so reaching the floor takes roughly fifty
 * consecutive drops into the *same* gap — unlikely, but "unlikely" is not "cannot",
 * and the failure mode would be two cards with identical ranks flickering past each
 * other. So the floor is checked and the workspace is renumbered when it is reached.
 */
final class BoardRank
{
    /** Gap between neighbours when ranks are handed out fresh. */
    public const STEP = 1000.0;

    /**
     * The smallest gap worth midpointing.
     *
     * Deliberately far above the precision floor rather than at it. A double only
     * fails to find a midpoint when the two values are adjacent representable
     * numbers, which for ranks of this magnitude is around 1e-13; stopping at 1e-9
     * leaves four orders of magnitude of headroom and still allows about forty
     * consecutive drops into the same gap before a renumber is needed.
     *
     * The first attempt used 1e-4, which is also correct and renumbers after
     * twenty-four. The reason to prefer this one is that renumbering rewrites every
     * issue in the workspace, so it is worth making rare.
     */
    public const MIN_GAP = 0.000000001;

    /**
     * A rank between two neighbours, or null when the gap has run out and the caller
     * should renumber first.
     *
     * Either end may be null: null above means "top of the board", null below means
     * "bottom".
     */
    public static function between(?float $above, ?float $below): ?float
    {
        if ($above === null && $below === null) {
            return 0.0;
        }

        if ($above === null) {
            return $below - self::STEP;
        }

        if ($below === null) {
            return $above + self::STEP;
        }

        // Handed the pair the wrong way round — a client that believes the board is
        // in a different order from the database. Refusing is better than writing a
        // rank that means the opposite of what was asked.
        if ($below <= $above) {
            return null;
        }

        if ($below - $above < self::MIN_GAP) {
            return null;
        }

        return $above + (($below - $above) / 2);
    }

    /**
     * Space a workspace's issues out again, keeping the order they are already in.
     *
     * Rare: only when a gap has been halved into nothing. Done in one statement
     * because doing it row by row over a large workspace is the kind of loop that
     * times out halfway and leaves the board in an order nobody chose.
     */
    public static function renumber(int $workspaceId): void
    {
        DB::statement(<<<'SQL'
            UPDATE issues SET board_rank = ordered.position * 1000.0
            FROM (
                SELECT id, row_number() OVER (
                    ORDER BY board_rank NULLS LAST, priority DESC, updated_at DESC, id
                ) AS position
                FROM issues
                WHERE workspace_id = ?
            ) AS ordered
            WHERE issues.id = ordered.id
        SQL, [$workspaceId]);
    }

    /** The rank a brand new issue gets: the bottom of the board. */
    public static function forNewIssue(int $workspaceId): float
    {
        $lowest = Issue::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->max('board_rank');

        return $lowest === null ? 0.0 : (float) $lowest + self::STEP;
    }
}
