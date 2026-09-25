import { clamp, safeUrl } from './redact';

/**
 * Ring buffers for the context that makes a bug report actionable.
 *
 * Installed at load, because the interesting things happen before anyone thinks to
 * click "report a bug". Everything is bounded, and the original console and fetch are
 * always called through — the host application must not be able to tell we are here.
 */

export interface ConsoleEntry {
    level: string;
    message: string;
    at: number;
}

export interface NetworkEntry {
    method: string;
    url: string;
    status: number | string;
    duration: number;
    at: number;
}

export interface CapturedError {
    message: string;
    stack?: string;
    at: number;
}

const CONSOLE_LIMIT = 50;
const NETWORK_LIMIT = 30;

const consoleLog: ConsoleEntry[] = [];
const networkLog: NetworkEntry[] = [];
let lastError: CapturedError | null = null;

function push<T>(buffer: T[], entry: T, limit: number) {
    buffer.push(entry);
    if (buffer.length > limit) buffer.shift();
}

export function installCapture(): void {
    const levels = ['log', 'info', 'warn', 'error', 'debug'] as const;

    for (const level of levels) {
        const original = console[level]?.bind(console);
        if (!original) continue;

        console[level] = (...args: unknown[]) => {
            push(
                consoleLog,
                {
                    level,
                    message: args.map((a) => clamp(a, 300)).join(' '),
                    at: Date.now(),
                },
                CONSOLE_LIMIT,
            );
            original(...args);
        };
    }

    // Method, URL, status and duration only. Never headers, never bodies.
    const originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = async (...args: Parameters<typeof fetch>) => {
            const started = performance.now();
            const request = args[0];
            const method =
                (args[1]?.method ?? (request instanceof Request ? request.method : 'GET')) || 'GET';
            const url = request instanceof Request ? request.url : String(request);

            try {
                const response = await originalFetch(...args);
                record(method, url, response.status, started);
                return response;
            } catch (error) {
                record(method, url, 'failed', started);
                throw error;
            }
        };
    }

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method: string, url: string, ...rest: unknown[]) {
        (this as XMLHttpRequest & { __buggie?: unknown }).__buggie = { method, url };
        // @ts-expect-error passthrough to the original signature
        return originalOpen.call(this, method, url, ...rest);
    };

    XMLHttpRequest.prototype.send = function (...args: unknown[]) {
        const meta = (this as XMLHttpRequest & { __buggie?: { method: string; url: string } }).__buggie;
        const started = performance.now();

        if (meta) {
            this.addEventListener('loadend', () =>
                record(meta.method, meta.url, this.status || 'failed', started),
            );
        }

        // @ts-expect-error passthrough to the original signature
        return originalSend.apply(this, args);
    };

    window.addEventListener('error', (event) => {
        lastError = {
            message: clamp(event.message, 2000),
            stack: event.error?.stack ? clamp(event.error.stack, 8000) : undefined,
            at: Date.now(),
        };
    });

    // A script, stylesheet, image or media file that failed to load. These errors
    // fire on the element and do not bubble, so the listener above never hears
    // them: only one on the capture phase does. Recorded with the console, which is
    // where a developer would have seen "GET …/app.js 404" for themselves.
    window.addEventListener(
        'error',
        (event) => {
            const entry = resourceFailure(event.target);
            if (entry) push(consoleLog, entry, CONSOLE_LIMIT);
        },
        true,
    );

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        lastError = {
            message: clamp(reason?.message ?? reason, 2000),
            stack: reason?.stack ? clamp(reason.stack, 8000) : undefined,
            at: Date.now(),
        };
    });
}

const RESOURCE_KINDS: Record<string, string> = {
    IMG: 'image',
    SCRIPT: 'script',
    LINK: 'stylesheet',
    VIDEO: 'video',
    AUDIO: 'audio',
    SOURCE: 'media',
    IFRAME: 'frame',
};

/**
 * A console entry for an element that failed to load, or null for anything else —
 * including the window itself, whose error events are script errors and are
 * handled separately. The address goes through safeUrl, like every URL the widget
 * sends, so a signed or tokened link does not leave the page with its secret.
 */
export function resourceFailure(target: EventTarget | null): ConsoleEntry | null {
    if (!(target instanceof Element)) return null;

    const kind = RESOURCE_KINDS[target.tagName];
    if (!kind) return null;

    const url =
        target.getAttribute('src') ??
        target.getAttribute('href') ??
        (target as HTMLImageElement).currentSrc ??
        '';

    // A <link> that is not a stylesheet (a preload, an icon) is still worth saying.
    const label = target.tagName === 'LINK' ? (target.getAttribute('rel') ?? kind) : kind;

    return {
        level: 'error',
        message: clamp(`Failed to load ${label}: ${url ? safeUrl(resolve(url)) : '(no address)'}`, 500),
        at: Date.now(),
    };
}

function resolve(url: string): string {
    try {
        return new URL(url, document.baseURI).href;
    } catch {
        return url;
    }
}

function record(method: string, url: string, status: number | string, started: number) {
    push(
        networkLog,
        {
            method: method.toUpperCase(),
            url: safeUrl(url),
            status,
            duration: Math.round(performance.now() - started),
            at: Date.now(),
        },
        NETWORK_LIMIT,
    );
}

export function getConsole(): ConsoleEntry[] {
    return [...consoleLog];
}

export function getNetwork(): NetworkEntry[] {
    return [...networkLog];
}

export function getError(): CapturedError | null {
    return lastError;
}

export function environment(release: string | null): Record<string, unknown> {
    return {
        url: safeUrl(location.href),
        referrer: safeUrl(document.referrer),
        title: clamp(document.title, 200),
        user_agent: clamp(navigator.userAgent, 512),
        language: navigator.language,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        viewport: `${window.innerWidth}x${window.innerHeight}`,
        screen: `${screen.width}x${screen.height}`,
        pixel_ratio: window.devicePixelRatio,
        release,
        captured_at: new Date().toISOString(),
    };
}
