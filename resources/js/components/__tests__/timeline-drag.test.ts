import { describe, expect, it } from 'vitest';
import { addDays, rescheduled } from '../timeline-chart';

/**
 * What a drag on the timeline saves. The pointer arithmetic is the browser's; the
 * rules — whole days, one end never passing the other, a one-date issue keeping its
 * one date — are these.
 */
const bar = { kind: 'bar' as const, anchor: 'due' as const, start: '2026-10-05', end: '2026-10-09', start_on: '2026-10-05', due_on: '2026-10-09' };

describe('rescheduled', () => {
    it('moves both dates when the bar is dragged', () => {
        expect(rescheduled(bar, 'move', 3)).toEqual({ start_on: '2026-10-08', due_on: '2026-10-12' });
    });

    it('moves only the end it was dragged by', () => {
        expect(rescheduled(bar, 'start', -2)).toEqual({ start_on: '2026-10-03', due_on: '2026-10-09' });
        expect(rescheduled(bar, 'end', 5)).toEqual({ start_on: '2026-10-05', due_on: '2026-10-14' });
    });

    it('stops one end at the other, so a bar is at least a day', () => {
        expect(rescheduled(bar, 'start', 10)).toEqual({ start_on: '2026-10-09', due_on: '2026-10-09' });
        expect(rescheduled(bar, 'end', -10)).toEqual({ start_on: '2026-10-05', due_on: '2026-10-05' });
    });

    it('moves a one-date issue by its one date and leaves the other missing', () => {
        const due = { ...bar, kind: 'milestone' as const, anchor: 'due' as const, start: '2026-10-09', start_on: null };
        expect(rescheduled(due, 'move', 2)).toEqual({ start_on: null, due_on: '2026-10-11' });

        const start = { ...bar, kind: 'milestone' as const, anchor: 'start' as const, end: '2026-10-05', due_on: null };
        expect(rescheduled(start, 'move', -1)).toEqual({ start_on: '2026-10-04', due_on: null });
    });
});

describe('addDays', () => {
    it('crosses months and the clocks changing without drifting', () => {
        expect(addDays('2026-10-30', 3)).toBe('2026-11-02');
        // The last Sunday of October: a local-time calculation loses an hour here.
        expect(addDays('2026-10-24', 1)).toBe('2026-10-25');
        expect(addDays('2026-10-25', 1)).toBe('2026-10-26');
    });
});
