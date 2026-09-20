<?php

namespace Tests\Unit;

use App\Support\Notifications\DueReminderSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ladder on its own, with no database and no clock. DueDateReminderTest proves
 * the command walks it; this proves the shape of it, including the quiet days, which
 * are the whole point and the easiest thing to lose in a refactor.
 */
class DueReminderScheduleTest extends TestCase
{
    #[Test]
    public function the_first_three_weeks_are_six_reminders(): void
    {
        $days = array_values(array_filter(
            range(-14, 21),
            fn (int $offset) => DueReminderSchedule::isReminderDay($offset),
        ));

        $this->assertSame([-3, -1, 0, 1, 3, 7, 14, 21], $days);
    }

    #[Test]
    public function nothing_is_sent_more_than_three_days_ahead(): void
    {
        // A bug tracker is not a calendar. A fortnight's warning is noise by the time
        // it matters, and noise is what gets the sender filtered.
        foreach (range(-60, -4) as $offset) {
            $this->assertFalse(
                DueReminderSchedule::isReminderDay($offset),
                "Offset {$offset} should be silent.",
            );
        }

        $this->assertSame(-3, DueReminderSchedule::EARLIEST_OFFSET);
        $this->assertTrue(DueReminderSchedule::isReminderDay(DueReminderSchedule::EARLIEST_OFFSET));
    }

    #[Test]
    public function chasing_settles_to_weekly_and_does_not_stop(): void
    {
        // Still open and still late a year on is still late. Going silent would turn
        // a missed deadline into a forgotten one.
        $this->assertTrue(DueReminderSchedule::isReminderDay(364));

        // But only on the seventh days, not every day in between.
        foreach (range(358, 363) as $offset) {
            $this->assertFalse(DueReminderSchedule::isReminderDay($offset));
        }
    }

    #[Test]
    public function the_sentence_says_which_side_of_the_date_it_is_on(): void
    {
        $this->assertSame('This is due in 3 days.', DueReminderSchedule::sentence(-3));
        $this->assertSame('This is due tomorrow.', DueReminderSchedule::sentence(-1));
        $this->assertSame('This is due today.', DueReminderSchedule::sentence(0));
        $this->assertSame('This was due yesterday.', DueReminderSchedule::sentence(1));
        $this->assertSame('This is 14 days overdue.', DueReminderSchedule::sentence(14));
    }
}
