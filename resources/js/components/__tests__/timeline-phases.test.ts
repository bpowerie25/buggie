import { describe, expect, it } from 'vitest';
import { barColour, initials, unfolded } from '../timeline-chart';

describe('folding a phase', () => {
    const rows: { key: string; group?: string | null }[] = [
        { key: 'phase-1', group: null },
        { key: 'KD-1', group: 'phase-1' },
        { key: 'KD-2', group: 'phase-1' },
        { key: 'phase-2', group: null },
        { key: 'KD-3', group: 'phase-2' },
    ];

    it('hides the issues under a collapsed phase and keeps its header', () => {
        expect(unfolded(rows, new Set(['phase-1'])).map((r) => r.key)).toEqual(['phase-1', 'phase-2', 'KD-3']);
    });

    it('draws everything when nothing is collapsed, including rows with no phase', () => {
        expect(unfolded([...rows, { key: 'KD-9' }], new Set()).map((r) => r.key)).toHaveLength(6);
    });
});

describe('colouring and owners', () => {
    const row = { status_color: '#f59e0b', assignee_id: 9 };

    it('uses the status colour, a steady colour per person, or the default classes', () => {
        expect(barColour(row, 'status')).toBe('#f59e0b');
        expect(barColour(row, 'assignee')).toBe(barColour({ status_color: null, assignee_id: 9 }, 'assignee'));
        expect(barColour(row, 'assignee')).not.toBe(barColour({ status_color: null, assignee_id: 10 }, 'assignee'));
        expect(barColour(row, 'state')).toBeUndefined();
        // A client's rows carry neither, and fall back rather than going blank.
        expect(barColour({ status_color: null, assignee_id: null }, 'status')).toBeUndefined();
    });

    it('turns a name into initials', () => {
        expect(initials('Dana Katherine Scully')).toBe('DS');
        expect(initials('matrix')).toBe('M');
    });
});
