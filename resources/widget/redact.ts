/**
 * Redaction.
 *
 * The widget runs inside our customers' applications, on their users' screens. It must
 * be boring and safe: the first thing a client's security reviewer asks is what this
 * script can see.
 *
 * Rules, all enforced here:
 *   - never read cookies, localStorage, or request/response bodies
 *   - never capture password fields or anything opted out with data-buggie-redact
 *   - strip credential-shaped query parameters from every captured URL
 */

const SECRET_PARAMS =
    /^(token|key|secret|password|passwd|auth|session|sig|signature|access_token|api_key|code)$/i;

/** Remove credential-shaped query parameters before a URL leaves the page. */
export function safeUrl(input: string | null | undefined): string {
    if (!input) return '';

    try {
        const url = new URL(input, location.href);

        url.searchParams.forEach((_value, name) => {
            if (SECRET_PARAMS.test(name)) url.searchParams.set(name, '[redacted]');
        });

        // Fragments routinely carry tokens in OAuth flows and are never useful to us.
        url.hash = '';

        return url.toString();
    } catch {
        return '[unparseable url]';
    }
}

/** Elements whose contents must never appear in a screenshot. */
export function redactedElements(root: ParentNode = document): Element[] {
    return [
        ...root.querySelectorAll<HTMLElement>(
            'input[type=password], [data-buggie-redact], .buggie-redact',
        ),
    ];
}

const MASK_STYLE_ID = 'buggie-mask-style';

const MASK_CSS = `[data-buggie-masked] {
  color: transparent !important;
  text-shadow: none !important;
  background-image: none !important;
  background-color: #94a3b8 !important;
  border-color: #94a3b8 !important;
  caret-color: transparent !important;
}
[data-buggie-masked] * { visibility: hidden !important; }`;

/**
 * Mask redacted regions *before* rasterising, by restyling them in the DOM.
 *
 * The obvious alternative — painting rectangles onto the finished canvas — has to
 * reproduce html2canvas's coordinate system exactly, and any disagreement about
 * scroll offset or scale silently draws the masks in the wrong place, leaving the
 * password on display. Letting the browser lay out the mask cannot be misaligned.
 *
 * Returns a function that puts everything back.
 */
export function maskForCapture(): () => void {
    const style = document.createElement('style');
    style.id = MASK_STYLE_ID;
    style.textContent = MASK_CSS;
    document.head.appendChild(style);

    const masked = redactedElements();

    for (const element of masked) {
        element.setAttribute('data-buggie-masked', '');
    }

    return () => {
        for (const element of masked) {
            element.removeAttribute('data-buggie-masked');
        }

        style.remove();
    };
}

/** Trim long values so one enormous log line cannot fill the payload. */
export function clamp(value: unknown, max = 500): string {
    let text: string;

    try {
        text =
            typeof value === 'string'
                ? value
                : value instanceof Error
                  ? `${value.name}: ${value.message}`
                  : JSON.stringify(value);
    } catch {
        text = String(value);
    }

    text = text ?? String(value);

    return text.length > max ? `${text.slice(0, max)}…` : text;
}
