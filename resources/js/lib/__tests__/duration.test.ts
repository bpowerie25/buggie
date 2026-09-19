import { describe, expect, it } from 'vitest';
import { formatDuration, parseDuration } from '../duration';

/**
 * The same cases as tests/Unit/DurationTest.php.
 *
 * Two implementations of one rule only stay in step if both are held to the same
 * table. If you add a case here, add it there.
 */
const VALID: [string, number][] = [
    ['90', 90],
    ['45m', 45],
    ['2h', 120],
    ['1h 30m', 90],
    ['1h30m', 90],
    ['1h30', 90],
    ['1.5h', 90],
    ['1,5h', 90],
    ['2:30', 150],
    ['2:05', 125],
    ['1H 30M', 90],
    ['   45m  ', 45],
    ['0.1h', 6],
    ['24h', 1440],
];

const INVALID = ['', '   ', 'ages', '0', '0h', '-30', '25h', '24:01', '2:60', '1h30x', '30m 1h'];

describe('parseDuration', () => {
    it.each(VALID)('parses %s', (input, expected) => {
        expect(parseDuration(input)).toBe(expected);
    });

    it.each(INVALID)('refuses %s', (input) => {
        expect(parseDuration(input)).toBeNull();
    });
});

describe('formatDuration', () => {
    it.each([
        [null, '—'],
        [0, '0m'],
        [45, '45m'],
        [60, '1h'],
        [90, '1h 30m'],
        [1440, '24h'],
        [-90, '-1h 30m'],
    ] as [number | null, string][])('formats %s', (minutes, expected) => {
        expect(formatDuration(minutes)).toBe(expected);
    });

    it('round-trips whatever it prints', () => {
        for (const minutes of [1, 7, 45, 59, 60, 61, 90, 125, 480, 1439, 1440]) {
            expect(parseDuration(formatDuration(minutes))).toBe(minutes);
        }
    });
});
