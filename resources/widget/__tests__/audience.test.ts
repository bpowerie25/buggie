import { beforeEach, describe, expect, it, vi } from 'vitest';
import { resolveOptIn, setOptIn, shouldShowLauncher } from '../audience';

describe('shouldShowLauncher', () => {
    it('shows the button by default', () => {
        expect(shouldShowLauncher(undefined, false)).toBe(true);
    });

    it('never shows it when the tag says false', () => {
        expect(shouldShowLauncher('false', true)).toBe(false);
    });

    it('shows it under opt-in only once this browser has opted in', () => {
        expect(shouldShowLauncher('opt-in', false)).toBe(false);
        expect(shouldShowLauncher('opt-in', true)).toBe(true);
    });

    it('falls through to showing it on a typo', () => {
        // A mistyped attribute should leave reporting working rather than silently
        // remove it from a client's live site.
        expect(shouldShowLauncher('opt in', false)).toBe(true);
        expect(shouldShowLauncher('FALSE', false)).toBe(true);
    });
});

describe('resolveOptIn', () => {
    beforeEach(() => {
        localStorage.clear();
        history.replaceState(null, '', 'https://acme.test/pricing');
    });

    it('is off until somebody opts in', () => {
        expect(resolveOptIn('')).toBe(false);
    });

    it('turns on from the query parameter and remembers it', () => {
        expect(resolveOptIn('?buggie=on')).toBe(true);
        // Remembered without the parameter on the next page.
        expect(resolveOptIn('')).toBe(true);
    });

    it('turns back off again', () => {
        resolveOptIn('?buggie=on');

        expect(resolveOptIn('?buggie=off')).toBe(false);
        expect(resolveOptIn('')).toBe(false);
    });

    it('removes the switch from the address bar', () => {
        // Otherwise it travels into shared links, bookmarks and analytics, and the
        // client's customers end up with the button after all.
        history.replaceState(null, '', 'https://acme.test/pricing?buggie=on&utm=x');

        resolveOptIn('?buggie=on&utm=x');

        expect(location.search).not.toContain('buggie');
        expect(location.search).toContain('utm=x');
    });

    it('ignores a value that is neither on nor off', () => {
        resolveOptIn('?buggie=on');

        expect(resolveOptIn('?buggie=maybe')).toBe(true);
    });

    it('survives storage being unavailable', () => {
        // Safari's private mode throws outright on setItem, and a thrown error here
        // would take the host application's page down with it.
        const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('QuotaExceededError');
        });
        const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('SecurityError');
        });

        expect(() => resolveOptIn('?buggie=on')).not.toThrow();
        expect(() => setOptIn(true)).not.toThrow();

        setItem.mockRestore();
        getItem.mockRestore();
    });
});
