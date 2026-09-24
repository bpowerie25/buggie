import { describe, expect, it } from 'vitest';
import { unfolded } from '../timeline-chart';

describe('folding a phase', () => {
    const rows: { key: string; phase?: string | null }[] = [
        { key: 'phase-1', phase: null },
        { key: 'KD-1', phase: 'phase-1' },
        { key: 'KD-2', phase: 'phase-1' },
        { key: 'phase-2', phase: null },
        { key: 'KD-3', phase: 'phase-2' },
    ];

    it('hides the issues under a collapsed phase and keeps its header', () => {
        expect(unfolded(rows, new Set(['phase-1'])).map((r) => r.key)).toEqual(['phase-1', 'phase-2', 'KD-3']);
    });

    it('draws everything when nothing is collapsed, including rows with no phase', () => {
        expect(unfolded([...rows, { key: 'KD-9' }], new Set()).map((r) => r.key)).toHaveLength(6);
    });
});
