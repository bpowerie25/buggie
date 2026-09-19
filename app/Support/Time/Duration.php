<?php

namespace App\Support\Time;

use InvalidArgumentException;

/**
 * Durations, as people type them and as they read them back.
 *
 * Stored everywhere as whole minutes. Hours as a decimal is the obvious alternative
 * and it is wrong: 20 minutes is 0.333… hours, so a column of them does not add up to
 * what a person would get with a calculator, and an invoice built on it is off by
 * seconds per row and pounds per month.
 */
final class Duration
{
    /** A single entry longer than this is a typo, not a day's work. */
    public const MAX_MINUTES = 24 * 60;

    /**
     * Parse "1h30", "1h 30m", "1.5h", "90m", "2:30" or "90" into minutes.
     *
     * A bare number is minutes. It is genuinely ambiguous — somebody typing "2"
     * probably means two hours — which is why every field that accepts this shows
     * what it understood, right next to the box, before anything is saved.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $input): int
    {
        $text = strtolower(trim($input));

        if ($text === '') {
            throw new InvalidArgumentException('Enter how long it took.');
        }

        // 2:30 — hours and minutes, the way a timesheet is written.
        if (preg_match('/^(\d+):([0-5]?\d)$/', $text, $m)) {
            return self::guard((int) $m[1] * 60 + (int) $m[2]);
        }

        // 90 — bare minutes.
        if (preg_match('/^\d+$/', $text)) {
            return self::guard((int) $text);
        }

        // 1h30m, 1h 30m, 1h30, 1.5h, 45m
        if (! preg_match('/^(?:(\d+(?:[.,]\d+)?)\s*h)?\s*(?:(\d+(?:[.,]\d+)?)\s*m?)?$/', $text, $m)) {
            throw new InvalidArgumentException('Try something like 1h 30m, 90m or 2:30.');
        }

        $hours = ($m[1] ?? '') === '' ? 0.0 : (float) str_replace(',', '.', $m[1]);
        $minutes = ($m[2] ?? '') === '' ? 0.0 : (float) str_replace(',', '.', $m[2]);

        if ($hours === 0.0 && $minutes === 0.0) {
            throw new InvalidArgumentException('Try something like 1h 30m, 90m or 2:30.');
        }

        // Rounded, not truncated: 0.1h is 6 minutes, and 1.51h should not quietly
        // become 90 minutes because the cast threw the remainder away.
        return self::guard((int) round($hours * 60 + $minutes));
    }

    /** Null when the input is not a duration, for callers that would rather not catch. */
    public static function tryParse(?string $input): ?int
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::parse($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** "2h 30m", "1h", "45m". */
    public static function format(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }

        if ($minutes === 0) {
            return '0m';
        }

        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return "{$sign}{$rest}m";
        }

        return $rest === 0 ? "{$sign}{$hours}h" : "{$sign}{$hours}h {$rest}m";
    }

    /**
     * Decimal hours, for a spreadsheet.
     *
     * Only ever produced on the way out. Two decimal places is what an invoice uses,
     * and the minutes remain the record.
     */
    public static function toHours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }

    private static function guard(int $minutes): int
    {
        if ($minutes <= 0) {
            throw new InvalidArgumentException('That is not a length of time.');
        }

        if ($minutes > self::MAX_MINUTES) {
            throw new InvalidArgumentException('One entry cannot be longer than 24 hours.');
        }

        return $minutes;
    }
}
