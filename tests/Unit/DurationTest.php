<?php

namespace Tests\Unit;

use App\Support\Time\Duration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DurationTest extends TestCase
{
    public static function valid(): array
    {
        return [
            'bare minutes' => ['90', 90],
            'minutes with unit' => ['45m', 45],
            'hours only' => ['2h', 120],
            'hours and minutes, spaced' => ['1h 30m', 90],
            'hours and minutes, joined' => ['1h30m', 90],
            'hours and a bare remainder' => ['1h30', 90],
            'decimal hours' => ['1.5h', 90],
            'decimal hours, comma' => ['1,5h', 90],
            'clock form' => ['2:30', 150],
            'clock form, single digit minutes' => ['2:05', 125],
            'uppercase' => ['1H 30M', 90],
            'padded' => ['   45m  ', 45],
            'a tenth of an hour' => ['0.1h', 6],
            'the maximum' => ['24h', 1440],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_parses(string $input, int $expected): void
    {
        $this->assertSame($expected, Duration::parse($input));
    }

    public static function invalid(): array
    {
        return [
            'empty' => [''],
            'whitespace' => ['   '],
            'prose' => ['ages'],
            'zero' => ['0'],
            'zero hours' => ['0h'],
            'negative' => ['-30'],
            'longer than a day' => ['25h'],
            'clock form over a day' => ['24:01'],
            'sixty minutes past the hour' => ['2:60'],
            'trailing rubbish' => ['1h30x'],
            'units out of order' => ['30m 1h'],
        ];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function it_refuses(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        Duration::parse($input);
    }

    #[Test]
    public function try_parse_returns_null_rather_than_throwing(): void
    {
        $this->assertNull(Duration::tryParse('ages'));
        $this->assertNull(Duration::tryParse(null));
        $this->assertNull(Duration::tryParse(''));
        $this->assertSame(90, Duration::tryParse('1h30m'), 'The positive control.');
    }

    public static function formatted(): array
    {
        return [
            [null, '—'],
            [0, '0m'],
            [45, '45m'],
            [60, '1h'],
            [90, '1h 30m'],
            [120, '2h'],
            [1440, '24h'],
            [-90, '-1h 30m'],
        ];
    }

    #[Test]
    #[DataProvider('formatted')]
    public function it_formats(?int $minutes, string $expected): void
    {
        $this->assertSame($expected, Duration::format($minutes));
    }

    #[Test]
    public function parsing_and_formatting_round_trip(): void
    {
        // The property that matters: what somebody is shown, typed back in, means the
        // same thing. A formatter that printed "1.5h" would break this.
        foreach ([1, 7, 45, 59, 60, 61, 90, 125, 480, 1439, 1440] as $minutes) {
            $this->assertSame(
                $minutes,
                Duration::parse(Duration::format($minutes)),
                "Formatting {$minutes} produced something that does not parse back.",
            );
        }
    }

    #[Test]
    public function hours_are_only_for_the_way_out(): void
    {
        // Twenty minutes is 0.333… hours. Storing that and adding it up is how an
        // invoice ends up wrong by a few pence a line.
        $this->assertSame('0.33', Duration::toHours(20));
        $this->assertSame('1.50', Duration::toHours(90));
        $this->assertSame('8.00', Duration::toHours(480));
    }
}
