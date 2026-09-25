import { beforeAll, describe, expect, it } from 'vitest';
import { getConsole, installCapture, resourceFailure } from '../capture';

/**
 * Files that fail to load: their error fires on the element and does not bubble, so
 * only a capture-phase listener hears it. These are the 404s a developer would see
 * in their own console and a reporter's report used to leave out.
 */
describe('failed resource loads', () => {
    beforeAll(() => installCapture());

    it('records a script or image that failed, with its full address', () => {
        const script = document.createElement('script');
        script.src = '/assets/app-4f2c.js';
        document.head.appendChild(script);
        script.dispatchEvent(new Event('error'));

        const img = document.createElement('img');
        img.setAttribute('src', 'https://cdn.example.com/logo.png');
        document.body.appendChild(img);
        img.dispatchEvent(new Event('error'));

        const messages = getConsole().map((entry) => entry.message);
        expect(messages).toContain('Failed to load script: https://acme.test/assets/app-4f2c.js');
        expect(messages).toContain('Failed to load image: https://cdn.example.com/logo.png');
        expect(getConsole().at(-1)?.level).toBe('error');
    });

    it('strips secrets from the address, as for every other URL the widget sends', () => {
        const img = document.createElement('img');
        img.setAttribute('src', 'https://cdn.example.com/private.png?token=abc123&w=200');

        expect(resourceFailure(img)?.message).not.toContain('abc123');
    });

    it('ignores the window and elements that are not loaded files', () => {
        expect(resourceFailure(window)).toBeNull();
        expect(resourceFailure(document.createElement('div'))).toBeNull();
        expect(resourceFailure(null)).toBeNull();
    });

    it('names a link by what it was for', () => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = '/css/site.css';

        expect(resourceFailure(link)?.message).toBe('Failed to load stylesheet: https://acme.test/css/site.css');
    });
});
