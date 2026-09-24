import { describe, expect, it } from 'vitest';
import { hours, load } from '../workload';

describe('how full a week is', () => {
    it('is amber from 85% of the hours and red over them', () => {
        expect(load(0, 1200)).toBe('none');
        expect(load(600, 1200)).toBe('ok');
        expect(load(1020, 1200)).toBe('near');
        expect(load(1260, 1200)).toBe('over');
        // Nobody chose a limit, so nothing is over it.
        expect(load(5000, null)).toBe('unknown');
    });

    it('writes hours briefly', () => {
        expect([hours(90), hours(600), hours(1335)]).toEqual(['1.5h', '10h', '22h']);
    });
});
