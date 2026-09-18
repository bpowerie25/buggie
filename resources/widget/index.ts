import { resolveOptIn, setOptIn, shouldShowLauncher } from './audience';
import { installCapture } from './capture';
import { Widget, type Identity } from './ui';

/**
 * Buggie reporter widget.
 *
 *   <script src="https://buggie.eu/w/pk_live_9f3a2b.js" async></script>
 *
 * The key is read from this script's own src, so the install is one tag with nothing
 * to configure and every key serves the identical cacheable bundle.
 */

declare global {
    interface Window {
        buggie?: BuggieApi;
    }
}

interface BuggieApi {
    identify(identity: Identity): void;
    setRelease(release: string): void;
    open(): void;
    close(): void;
    isSupported(): boolean;
    /** Show or hide the launcher for this browser, for data-launcher="opt-in". */
    enable(): void;
    disable(): void;
}

function currentScript(): HTMLScriptElement | null {
    if (document.currentScript instanceof HTMLScriptElement) {
        return document.currentScript;
    }

    // async/defer scripts lose document.currentScript, so fall back to a src match.
    return document.querySelector<HTMLScriptElement>('script[src*="/w/"][src$=".js"]');
}

function boot() {
    const script = currentScript();
    const src = script?.src ?? '';
    const match = src.match(/\/w\/([A-Za-z0-9_]+)\.js/);

    if (!match) {
        console.warn('[buggie] Could not determine the widget key from the script URL.');
        return;
    }

    const endpoint = new URL(src).origin;

    // Buffers must be installed immediately: the interesting things happen well before
    // anyone thinks to click "report a bug".
    installCapture();

    const widget = new Widget({
        endpoint,
        key: match[1],
        requireEmail: script?.dataset.requireEmail === 'true',
        captureScreenshot: script?.dataset.screenshot !== 'false',
        // "false" hides the button entirely, for apps that would rather call
        // buggie.open() from their own menu. "opt-in" hides it until somebody
        // visits ?buggie=on, so a client's own customers never see it without
        // their developer having to wire anything up. See audience.ts.
        launcher: shouldShowLauncher(script?.dataset.launcher, resolveOptIn()),
    });

    const api: BuggieApi = {
        identify: (identity) => Object.assign(widget.identity, identity ?? {}),
        setRelease: (release) => (widget.release = release),
        open: () => void widget.show(),
        close: () => widget.dismiss(),
        // Lets a host application decide whether to offer reporting at all.
        isSupported: () => typeof document.body.attachShadow === 'function',
        enable: () => setOptIn(true),
        disable: () => setOptIn(false),
    };

    // Replay anything queued before this script finished loading.
    const queued = window.buggie as unknown as { q?: [keyof BuggieApi, unknown][] } | undefined;
    window.buggie = api;

    for (const [method, argument] of queued?.q ?? []) {
        (api[method] as (value: unknown) => void)?.(argument);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
