/**
 * Who sees the reporter.
 *
 * The default is a floating button for everybody, which is right for a public beta
 * and wrong for most client work: an agency putting Buggie on a client's live shop
 * does not want the shop's customers looking at a "Report a bug" button.
 *
 * Three answers, in order of how well they work:
 *
 *   1. Do not render the script tag for customers at all. Nothing loads, nothing
 *      shows, and nothing can be turned on. Needs a conditional in their template.
 *   2. `data-launcher="false"` — no button; the host app calls `buggie.open()` from
 *      its own admin menu. Needs their developer to wire up a trigger.
 *   3. `data-launcher="opt-in"` — no button until someone visits `?buggie=on` in
 *      that browser. Needs nothing from their developer, which is the point.
 *
 * Option 3 hides the interface; it does not restrict it. The key is in the page
 * source either way, so a curious customer who finds this could switch it on. That
 * is a presentation choice, not a security boundary — the origin allowlist and not
 * serving the tag are the things that actually restrict.
 */

const STORAGE_KEY = 'buggie.enabled';
const QUERY_KEY = 'buggie';

/** Reading storage throws outright in some privacy modes, so never let it escape. */
function read(): boolean {
    try {
        return localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function write(enabled: boolean): void {
    try {
        if (enabled) {
            localStorage.setItem(STORAGE_KEY, '1');
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // A browser that refuses storage just means opting in lasts one page view.
    }
}

/**
 * Apply `?buggie=on` / `?buggie=off`, and report whether this browser is opted in.
 *
 * The parameter is removed from the address bar afterwards, so the switch does not
 * travel into a shared link, a bookmark, or an analytics report.
 */
export function resolveOptIn(search: string = location.search): boolean {
    let enabled = read();

    const value = new URLSearchParams(search).get(QUERY_KEY);

    if (value === 'on' || value === 'off') {
        enabled = value === 'on';
        write(enabled);
        stripQuery();
    }

    return enabled;
}

function stripQuery(): void {
    try {
        const url = new URL(location.href);
        url.searchParams.delete(QUERY_KEY);
        history.replaceState(null, '', url.toString());
    } catch {
        // Not worth breaking the page over a tidy address bar.
    }
}

export function setOptIn(enabled: boolean): void {
    write(enabled);
}

/**
 * Whether to show the floating button, given the script tag's `data-launcher`.
 *
 * Unrecognised values fall through to showing it: a typo should leave reporting
 * working rather than silently remove it from the client's site.
 */
export function shouldShowLauncher(setting: string | undefined, optedIn: boolean): boolean {
    if (setting === 'false') return false;
    if (setting === 'opt-in') return optedIn;

    return true;
}
