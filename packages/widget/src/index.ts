/**
 * @buggie/widget — a typed loader for the Buggie bug reporter.
 *
 * This package deliberately does **not** bundle the widget. It injects the script
 * your Buggie instance already serves at `/w/{key}.js` and gives you a typed,
 * SSR-safe API around it.
 *
 * That matters for a self-hostable product: the widget a visitor runs always matches
 * the server it reports to, so upgrading Buggie upgrades the widget without anybody
 * redeploying their front end. Bundling it would mean every customer shipping a copy
 * that drifts out of date.
 */

export interface Identity {
    /** Your own identifier for the person — an id, a customer reference, anything. */
    id?: string | number;
    email?: string;
    name?: string;
    /**
     * HMAC-SHA256 of `${id}:${email}` with the widget key's secret, computed on your
     * server — never in the browser. Makes the report "verified".
     */
    user_hash?: string;
    [key: string]: unknown;
}

export interface BuggieOptions {
    /** The public widget key from project settings, e.g. `pk_live_9f3a2b`. */
    key: string;

    /**
     * Where Buggie lives, e.g. `https://buggie.eu` or your own install.
     * Defaults to `https://buggie.eu`.
     */
    endpoint?: string;

    /**
     * Who sees the floating "Report a bug" button.
     *
     * `true` (default) shows it to everybody, which is right for a UAT site where
     * every visitor is a tester. `false` draws nothing, for apps that would rather
     * call `open()` from their own menu, shortcut or error boundary.
     *
     * `'opt-in'` draws nothing until someone visits `?buggie=on` in that browser,
     * which is remembered. That is the one to use on a live site: the client's staff
     * switch it on once and their own customers never see it.
     */
    launcher?: boolean | 'opt-in';

    /** Offer a screenshot. Default true. */
    screenshot?: boolean;

    /** Require an email address before a report can be sent. Default false. */
    requireEmail?: boolean;

    /** Attached to reports so you know which build broke. */
    release?: string;

    /** Set straight away, saving a separate identify() call. */
    identity?: Identity;
}

interface WidgetApi {
    identify(identity: Identity): void;
    setRelease(release: string): void;
    open(): void;
    close(): void;
    isSupported(): boolean;
}

declare global {
    interface Window {
        buggie?: WidgetApi;
    }
}

const DEFAULT_ENDPOINT = 'https://buggie.eu';
const SCRIPT_ATTRIBUTE = 'data-buggie-loader';

let loading: Promise<WidgetApi | null> | null = null;

/** Nothing here touches the DOM at import time, so importing on the server is safe. */
function canRun(): boolean {
    return typeof window !== 'undefined' && typeof document !== 'undefined';
}

/**
 * Load the widget. Safe to call more than once — later calls return the same
 * promise rather than injecting a second script.
 *
 * Resolves to the API, or to null where the widget cannot run: during server
 * rendering, or if the script is blocked.
 */
export function init(options: BuggieOptions): Promise<WidgetApi | null> {
    if (!canRun()) {
        return Promise.resolve(null);
    }

    if (loading) {
        return loading;
    }

    if (!options?.key) {
        console.warn('[buggie] init() needs a key. Find it in your project settings.');
        return Promise.resolve(null);
    }

    const endpoint = (options.endpoint ?? DEFAULT_ENDPOINT).replace(/\/+$/, '');

    loading = new Promise<WidgetApi | null>((resolve) => {
        const existing = document.querySelector<HTMLScriptElement>(`script[${SCRIPT_ATTRIBUTE}]`);

        const done = () => {
            const api = window.buggie ?? null;

            if (api) {
                if (options.identity) api.identify(options.identity);
                if (options.release) api.setRelease(options.release);
            }

            resolve(api);
        };

        if (existing) {
            // Somebody already added it — wait for that one rather than adding another.
            window.buggie ? done() : existing.addEventListener('load', done, { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = `${endpoint}/w/${encodeURIComponent(options.key)}.js`;
        script.async = true;
        script.setAttribute(SCRIPT_ATTRIBUTE, '');

        if (options.launcher === false) script.dataset.launcher = 'false';
        if (options.launcher === 'opt-in') script.dataset.launcher = 'opt-in';
        if (options.screenshot === false) script.dataset.screenshot = 'false';
        if (options.requireEmail) script.dataset.requireEmail = 'true';

        script.addEventListener('load', done, { once: true });

        // A blocked script, a strict CSP or an offline visitor must not break the host
        // application. Reporting is a nicety; the app is not.
        script.addEventListener('error', () => resolve(null), { once: true });

        document.head.appendChild(script);
    });

    return loading;
}

/** Attach details about whoever is using the app, so reports arrive attributed. */
export async function identify(identity: Identity): Promise<void> {
    (await loading)?.identify(identity);
}

/** Tag reports with the build they came from. */
export async function setRelease(release: string): Promise<void> {
    (await loading)?.setRelease(release);
}

/** Open the reporter — for your own button, menu item or keyboard shortcut. */
export async function open(): Promise<void> {
    (await loading)?.open();
}

export async function close(): Promise<void> {
    (await loading)?.close();
}

/**
 * Whether the widget can run here. False during server rendering, and in browsers
 * without shadow DOM — useful for deciding whether to show your own button at all.
 */
export async function isSupported(): Promise<boolean> {
    return (await loading)?.isSupported() ?? false;
}

/** Escape hatch for tests, and for tearing down between hot reloads. */
export function reset(): void {
    loading = null;
    document.querySelectorAll(`script[${SCRIPT_ATTRIBUTE}]`).forEach((s) => s.remove());
    delete window.buggie;
}

// Named exports only, deliberately. Mixing in a default makes CommonJS consumers
// write `require('@buggie/widget').default`, which nobody expects.
