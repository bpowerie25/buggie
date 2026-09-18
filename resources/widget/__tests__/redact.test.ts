import { beforeEach, describe, expect, it } from 'vitest';
import { clamp, maskForCapture, redactedElements, safeUrl } from '../redact';

describe('safeUrl', () => {
    it('replaces credential-shaped parameters and keeps the rest', () => {
        const safe = safeUrl('https://acme.test/reset?token=supersecret&page=2');

        expect(safe).not.toContain('supersecret');
        expect(safe).toContain('token=%5Bredacted%5D');
        // A redacted URL still has to be a useful one.
        expect(safe).toContain('page=2');
    });

    // Kept in step with Redactor.swift deliberately: a name stripped on one platform
    // and not the other is a leak that only shows up on half the reports.
    it.each([
        'token', 'key', 'secret', 'password', 'passwd', 'auth',
        'session', 'sig', 'signature', 'access_token', 'api_key', 'code',
    ])('redacts %s regardless of casing', (name) => {
        expect(safeUrl(`https://acme.test/x?${name.toUpperCase()}=leaked`)).not.toContain('leaked');
    });

    it('drops the fragment entirely', () => {
        // OAuth flows park tokens there and it is never useful to us.
        const safe = safeUrl('https://acme.test/cb#access_token=leaked&state=x');

        expect(safe).not.toContain('leaked');
        expect(safe).not.toContain('#');
    });

    it('does not leak unparseable input back out', () => {
        expect(safeUrl('http://[')).toBe('[unparseable url]');
        expect(safeUrl(null)).toBe('');
    });
});

describe('redactedElements', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <input type="password" id="pw">
            <input type="text" id="plain">
            <div data-buggie-redact id="opted"></div>
            <div class="buggie-redact" id="classed"></div>
            <div id="ordinary"></div>
        `;
    });

    it('finds password fields and both opt-out forms, and nothing else', () => {
        const ids = redactedElements().map((element) => element.id).sort();

        expect(ids).toEqual(['classed', 'opted', 'pw']);
    });
});

describe('maskForCapture', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
        document.body.innerHTML = '<input type="password" id="pw"><div id="safe"></div>';
    });

    it('masks by restyling the DOM rather than drawing over it', () => {
        // The alternative — painting rectangles onto the finished canvas — has to
        // reproduce html2canvas's coordinate system exactly, and any disagreement
        // silently leaves the password on display. This is the whole reason the
        // widget works the way it does.
        const restore = maskForCapture();

        expect(document.getElementById('pw')!.hasAttribute('data-buggie-masked')).toBe(true);
        expect(document.getElementById('safe')!.hasAttribute('data-buggie-masked')).toBe(false);
        expect(document.getElementById('buggie-mask-style')).not.toBeNull();

        restore();
    });

    it('puts the host application back exactly as it found it', () => {
        const restore = maskForCapture();
        restore();

        expect(document.querySelectorAll('[data-buggie-masked]')).toHaveLength(0);
        expect(document.getElementById('buggie-mask-style')).toBeNull();
    });
});

describe('clamp', () => {
    it('bounds long values', () => {
        expect(clamp('x'.repeat(5000), 100)).toHaveLength(101);
    });

    it('survives values that cannot be serialised', () => {
        const circular: Record<string, unknown> = {};
        circular.self = circular;

        expect(() => clamp(circular)).not.toThrow();
    });

    it('describes errors rather than stringifying them to [object Object]', () => {
        expect(clamp(new TypeError('boom'))).toBe('TypeError: boom');
    });
});
