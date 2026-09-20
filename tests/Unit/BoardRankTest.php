<?php

namespace Tests\Unit;

use App\Support\Issues\BoardRank;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BoardRankTest extends TestCase
{
    #[Test]
    public function an_empty_board_starts_somewhere(): void
    {
        $this->assertSame(0.0, BoardRank::between(null, null));
    }

    #[Test]
    public function the_top_and_the_bottom_are_real_destinations(): void
    {
        // Null above means "above everything", which is a drop people make constantly
        // and which must not be read as a missing neighbour.
        $this->assertSame(0.0, BoardRank::between(null, 1000.0));
        $this->assertSame(2000.0, BoardRank::between(1000.0, null));
    }

    #[Test]
    public function a_card_lands_between_its_neighbours(): void
    {
        $rank = BoardRank::between(1000.0, 2000.0);

        $this->assertSame(1500.0, $rank);
        $this->assertGreaterThan(1000.0, $rank);
        $this->assertLessThan(2000.0, $rank);
    }

    #[Test]
    public function neighbours_the_wrong_way_round_are_refused(): void
    {
        // A client working from a board that has since changed. Writing a rank that
        // means the opposite of what was asked is worse than refusing.
        $this->assertNull(BoardRank::between(2000.0, 1000.0));
        $this->assertNull(BoardRank::between(1000.0, 1000.0));
    }

    #[Test]
    public function an_exhausted_gap_is_refused_rather_than_collapsed(): void
    {
        // Below the floor the midpoint of two doubles can equal one of them, and the
        // card would land exactly on top of its neighbour.
        $this->assertNull(BoardRank::between(1.0, 1.0 + (BoardRank::MIN_GAP / 2)));
    }

    #[Test]
    public function repeated_drops_into_the_same_gap_stay_ordered(): void
    {
        // The property that matters, and the one an off-by-one in the midpoint would
        // break: every insert lands strictly between the two cards it was aimed at.
        $above = 0.0;
        $below = 1000.0;

        for ($i = 0; $i < 40; $i++) {
            $rank = BoardRank::between($above, $below);

            if ($rank === null) {
                /*
                 * Hitting the floor is allowed; landing on a neighbour is not.
                 *
                 * How many drops that takes is arithmetic, not a guess: the gap
                 * halves each time, so it is log2(STEP / MIN_GAP). Asserted from the
                 * constants so that changing either cannot leave this test passing
                 * for the wrong reason — which is what happened when the bound was
                 * hardcoded at 30 and the real answer was 24.
                 */
                $expected = (int) floor(log(BoardRank::STEP / BoardRank::MIN_GAP, 2));

                $this->assertGreaterThanOrEqual(
                    $expected - 1,
                    $i,
                    'The gap ran out sooner than halving STEP down to MIN_GAP allows.',
                );

                return;
            }

            $this->assertGreaterThan($above, $rank);
            $this->assertLessThan($below, $rank);

            // Always drop just under the card we last placed, the worst case.
            $above = $rank;
        }

        $this->addToAssertionCount(1);
    }
}
