import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Widget } from '../ui';

/**
 * The project's settings reaching the widget.
 *
 * They were previously read only from `data-` attributes, so the checkboxes in
 * project settings were stored and never consulted: the screen said one thing and
 * the widget did another.
 */
describe('widget configuration', () => {
    const base = {
        endpoint: 'https://buggie.test',
        key: 'pk_test',
        requireEmail: false,
        captureScreenshot: true,
        launcher: false,
    };

    beforeEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    function respondWith(settings: Record<string, unknown>) {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            json: async () => settings,
        }));

        vi.stubGlobal('fetch', fetchMock);

        return fetchMock;
    }

    it('asks the server what the project decided, when the reporter opens', async () => {
        // Not at page load: most visitors never report anything, and the widget's
        // whole argument is that it costs them nothing.
        //
        // Screenshots off in the response, so show() returns without waiting on the
        // html2canvas CDN — this test is about when the config is asked for.
        const fetchMock = respondWith({ require_email: true, capture_screenshot: false });

        const widget = new Widget({ ...base });

        expect(fetchMock).not.toHaveBeenCalled();

        await widget.show();

        expect(fetchMock).toHaveBeenCalledWith(
            'https://buggie.test/api/ingest/pk_test/config',
            expect.anything(),
        );
    });

    it('takes the project over the script tag', async () => {
        const widget = new Widget({ ...base, requireEmail: false });
        respondWith({ require_email: true, capture_screenshot: false });

        await widget.show();

        expect(widget.config.requireEmail).toBe(true);
    });

    it('lets the tag switch a screenshot off but never on', async () => {
        // A project deciding not to collect images is not a per-page choice.
        const widget = new Widget({ ...base, captureScreenshot: false });
        respondWith({ require_email: false, capture_screenshot: true });


        await widget.show();

        expect(widget.config.captureScreenshot).toBe(false);
    });

    it('honours a project that has switched screenshots off', async () => {
        const widget = new Widget({ ...base, captureScreenshot: true });
        respondWith({ require_email: false, capture_screenshot: false });

        await widget.show();

        expect(widget.config.captureScreenshot).toBe(false);
    });

    it('opens anyway when the request fails', async () => {
        // Offline, blocked by a CSP, or simply slow. A reporter that refuses to open
        // because a settings call failed is worse than one using its defaults.
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new Error('blocked');
        }));

        // Screenshots off, so this test waits on the failed config call rather than
        // on the html2canvas CDN.
        const widget = new Widget({ ...base, requireEmail: true, captureScreenshot: false });

        await expect(widget.show()).resolves.not.toThrow();

        expect(widget.config.requireEmail).toBe(true);
    });

    it('asks only once', async () => {
        const fetchMock = respondWith({ require_email: true, capture_screenshot: false });

        const widget = new Widget({ ...base });

        await widget.show();
        widget.dismiss();
        await widget.show();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
