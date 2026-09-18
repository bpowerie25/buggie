import { beforeEach, describe, expect, it, vi } from 'vitest';
import { capture } from '../screenshot';

/**
 * The ordering test.
 *
 * Whether the masks exist is not the question — `redact.test.ts` covers that. The
 * question is whether they are in place *at the moment the page is rasterised*. Get
 * that wrong and every other redaction test still passes while the password goes out
 * in the image. The native SDK had exactly this bug: it hid the views and then
 * rendered from a buffer composited before the hiding.
 */
describe('capture', () => {
    let maskedWhenRasterised: string[] | null;

    beforeEach(() => {
        maskedWhenRasterised = null;
        document.head.innerHTML = '';
        document.body.innerHTML = '<input type="password" id="pw"><div id="safe"></div>';

        (window as unknown as { html2canvas: unknown }).html2canvas = vi.fn(async () => {
            // Observe the DOM as html2canvas would see it, not before or after.
            maskedWhenRasterised = [...document.querySelectorAll('[data-buggie-masked]')].map(
                (element) => element.id,
            );

            return document.createElement('canvas');
        });
    });

    it('has the password field masked at the moment the page is rasterised', async () => {
        await capture(document.createElement('div'));

        expect(maskedWhenRasterised).toEqual(['pw']);
    });

    it('unmasks afterwards even though the page was captured', async () => {
        await capture(document.createElement('div'));

        expect(document.querySelectorAll('[data-buggie-masked]')).toHaveLength(0);
        expect(document.getElementById('buggie-mask-style')).toBeNull();
    });

    it('unmasks even when rasterising throws', async () => {
        // A half-masked host application would be a worse bug than a missing
        // screenshot: the customer's own users would see it.
        (window as unknown as { html2canvas: unknown }).html2canvas = vi.fn(async () => {
            throw new Error('canvas tainted');
        });

        await expect(capture(document.createElement('div'))).resolves.toBeNull();

        expect(document.querySelectorAll('[data-buggie-masked]')).toHaveLength(0);
        expect(document.getElementById('buggie-mask-style')).toBeNull();
    });

    it('hides the widget itself, then puts it back', async () => {
        const panel = document.createElement('div');
        panel.style.visibility = 'visible';

        await capture(panel);

        // The reporter should photograph their application, not our panel.
        expect(panel.style.visibility).toBe('visible');
    });
});
