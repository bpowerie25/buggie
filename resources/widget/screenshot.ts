import { maskForCapture } from './redact';

/**
 * Screenshot capture and annotation.
 *
 * html2canvas is ~50KB gzipped, which is more than the whole rest of this widget, so
 * it is fetched from a CDN only when someone actually opens the reporter. The base
 * bundle every customer's visitors download stays tiny.
 */

const HTML2CANVAS_SRC =
    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
// Pinned: the script runs inside our customers' pages, so a changed file on the CDN
// must fail to load rather than run. Matches the hash cdnjs publishes for 1.4.1.
const HTML2CANVAS_INTEGRITY =
    'sha512-BNaRQnYJYiPSqHHDb58B0yaPfCu+Wgds8Gp/gU33kqBtgNS4tSPHuGibyoeqMV/TJlSKda6FXzoEyYGjTe+vXA==';

const MAX_WIDTH = 1600;

/** A slow or blocked CDN must not leave the reporter staring at a spinner. */
const LOAD_TIMEOUT_MS = 6000;

type Html2Canvas = (element: HTMLElement, options?: Record<string, unknown>) => Promise<HTMLCanvasElement>;

let loading: Promise<Html2Canvas | null> | null = null;

function load(): Promise<Html2Canvas | null> {
    const existing = (window as unknown as { html2canvas?: Html2Canvas }).html2canvas;
    if (existing) return Promise.resolve(existing);

    if (!loading) {
        loading = new Promise<Html2Canvas | null>((resolve) => {
            const settle = (value: Html2Canvas | null) => resolve(value);
            const timer = setTimeout(() => settle(null), LOAD_TIMEOUT_MS);

            const script = document.createElement('script');
            script.src = HTML2CANVAS_SRC;
            script.integrity = HTML2CANVAS_INTEGRITY;
            script.crossOrigin = 'anonymous';
            script.onload = () => {
                clearTimeout(timer);
                settle((window as unknown as { html2canvas?: Html2Canvas }).html2canvas ?? null);
            };
            // A strict CSP in the host app will block this. The reporter still works;
            // it just has no image, which is why the form never depends on one.
            script.onerror = () => {
                clearTimeout(timer);
                settle(null);
            };
            document.head.appendChild(script);
        });
    }

    return loading;
}

/**
 * Rasterise the current viewport.
 *
 * `hide` is the widget's own root, hidden during capture so the reporter photographs
 * their app rather than our panel.
 */
export async function capture(hide: HTMLElement): Promise<HTMLCanvasElement | null> {
    const html2canvas = await load();
    if (!html2canvas) return null;

    const previousVisibility = hide.style.visibility;
    hide.style.visibility = 'hidden';

    // Password fields and opted-out regions are restyled as solid blocks before
    // rasterising, so the browser positions the masks and they cannot be misaligned.
    const unmask = maskForCapture();

    try {
        const scale = Math.min(1, MAX_WIDTH / window.innerWidth);

        const canvas = await html2canvas(document.body, {
            // A transparent canvas becomes a black JPEG, so fall back to the page's
            // own background.
            backgroundColor: pageBackground(),
            useCORS: true,
            logging: false,
            scale,
            width: window.innerWidth,
            height: window.innerHeight,
            x: window.scrollX,
            y: window.scrollY,
            scrollX: 0,
            scrollY: 0,
            windowWidth: window.innerWidth,
            windowHeight: window.innerHeight,
            /*
             * Left out of the copy entirely, not merely hidden. html2canvas copies a
             * shadow root's children — our stylesheet included — into the light DOM of
             * the page it clones, where `* { box-sizing: border-box }` and our fonts
             * then restyled the whole customer page: every padded or bordered element
             * changed size, everything below moved, and the picture was of a different
             * part of the page from the one on screen, further off the further down.
             */
            ignoreElements: (element: Element) => element === hide,
        });

        /*
         * Handed on as a copy, on a canvas nobody else has drawn with.
         *
         * html2canvas leaves its own state behind on the canvas it returns: a
         * translate by the scroll position it cropped at, so on a page scrolled
         * 2,500 pixels down every box drawn afterwards landed 2,500 pixels above the
         * pointer, and an inline width and height in the page's CSS pixels, which
         * beat our styles and cropped the preview. A fresh canvas has neither, and
         * whatever else it might leave set — a clip, a blend mode — goes with them.
         */
        const clean = document.createElement('canvas');
        clean.width = canvas.width;
        clean.height = canvas.height;
        clean.getContext('2d')?.drawImage(canvas, 0, 0);

        return clean;
    } catch {
        return null;
    } finally {
        unmask();
        hide.style.visibility = previousVisibility;
    }
}

/** The nearest opaque background behind the page, so the capture is never transparent. */
function pageBackground(): string {
    for (const element of [document.body, document.documentElement]) {
        const colour = getComputedStyle(element).backgroundColor;

        if (colour && colour !== 'transparent' && !colour.startsWith('rgba(0, 0, 0, 0')) {
            return colour;
        }
    }

    return '#ffffff';
}

export type Tool = 'box' | 'blur';

/**
 * Lets the reporter mark what is wrong and hide anything they would rather not send.
 * This is the single highest-value part of the whole capture: a screenshot with a red
 * box around the broken thing is worth more than three paragraphs of description.
 */
export function attachAnnotator(canvas: HTMLCanvasElement, getTool: () => Tool) {
    const context = canvas.getContext('2d');
    if (!context) return;

    // Drawing happens in the image's own pixels, whatever made the canvas. getImageData
    // and putImageData ignore the transform and strokeRect does not, so a transform
    // left over would put the marks somewhere the snapshot is not.
    context.setTransform(1, 0, 0, 1, 0, 0);

    let start: { x: number; y: number } | null = null;
    let snapshot: ImageData | null = null;

    /*
     * The pointer against the image as it is drawn on screen, scaled to its pixels.
     * clientX and the element's screen rectangle are in the same coordinates even on
     * a page that uses CSS zoom; offsetX is not — under zoom Chrome reports it in a
     * different space, and the box landed well to the left of the pointer. The image
     * has no border in the editor, so its rectangle is exactly the drawing surface.
     */
    const position = (event: PointerEvent) => {
        const box = canvas.getBoundingClientRect();

        return toCanvasPoint(event.clientX - box.left, event.clientY - box.top, box.width, box.height, canvas.width, canvas.height);
    };

    // A drag interrupted — by the browser taking the touch for a scroll, or the
    // pointer leaving the window — leaves the image as it was before it started.
    const cancel = () => {
        if (snapshot) context.putImageData(snapshot, 0, 0);
        start = null;
        snapshot = null;
    };

    canvas.addEventListener('pointercancel', cancel);

    canvas.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        start = position(event);
        snapshot = context.getImageData(0, 0, canvas.width, canvas.height);
    });

    canvas.addEventListener('pointermove', (event) => {
        if (!start || !snapshot) return;

        const current = position(event);
        context.putImageData(snapshot, 0, 0);
        draw(context, start, current, getTool(), true);
    });

    canvas.addEventListener('pointerup', (event) => {
        if (!start || !snapshot) return;

        const end = position(event);
        context.putImageData(snapshot, 0, 0);
        draw(context, start, end, getTool(), false);

        start = null;
        snapshot = null;
    });
}

/** From a point in the canvas's displayed size to the same point in its pixels. */
export function toCanvasPoint(
    shownX: number,
    shownY: number,
    shownWidth: number,
    shownHeight: number,
    pixelWidth: number,
    pixelHeight: number,
): { x: number; y: number } {
    const clamp = (value: number, max: number) => Math.min(Math.max(value, 0), max);

    return {
        x: clamp(shownWidth > 0 ? (shownX / shownWidth) * pixelWidth : 0, pixelWidth),
        y: clamp(shownHeight > 0 ? (shownY / shownHeight) * pixelHeight : 0, pixelHeight),
    };
}

function draw(
    context: CanvasRenderingContext2D,
    from: { x: number; y: number },
    to: { x: number; y: number },
    tool: Tool,
    preview: boolean,
) {
    const x = Math.min(from.x, to.x);
    const y = Math.min(from.y, to.y);
    const width = Math.abs(to.x - from.x);
    const height = Math.abs(to.y - from.y);

    if (width < 3 || height < 3) return;

    if (tool === 'box') {
        context.save();
        context.strokeStyle = '#ef4444';
        context.lineWidth = 3;
        context.setLineDash(preview ? [6, 4] : []);
        context.strokeRect(x, y, width, height);
        context.restore();

        return;
    }

    pixelate(context, x, y, width, height);
}

/** Pixelation rather than a blur: it cannot be reversed by sharpening. */
function pixelate(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
) {
    const block = Math.max(8, Math.round(Math.min(width, height) / 6));

    for (let px = x; px < x + width; px += block) {
        for (let py = y; py < y + height; py += block) {
            const sample = context.getImageData(px, py, 1, 1).data;
            context.fillStyle = `rgb(${sample[0]},${sample[1]},${sample[2]})`;
            context.fillRect(px, py, Math.min(block, x + width - px), Math.min(block, y + height - py));
        }
    }
}

export function toBlob(canvas: HTMLCanvasElement): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.8));
}
