import { environment, getConsole, getError, getNetwork } from './capture';
import { attachAnnotator, capture, toBlob, type Tool } from './screenshot';

export interface WidgetConfig {
    endpoint: string;
    key: string;
    requireEmail: boolean;
    captureScreenshot: boolean;

    /**
     * Whether to draw the floating button.
     *
     * Most serious integrations already have somewhere sensible to put "Report a
     * bug" — a help menu, a keyboard shortcut, an error boundary — and want to call
     * buggie.open() from there rather than accept a bug in the corner of every page.
     */
    launcher: boolean;
}

export interface Identity {
    id?: string | number;
    email?: string;
    name?: string;
    /**
     * HMAC-SHA256 of `${id}:${email}` with the widget key's secret, computed on your
     * server. Proves this is the signed-in person you say it is. Never stored.
     */
    user_hash?: string;
    [key: string]: unknown;
}

/** Scoped in a shadow root so the host application's CSS cannot reach it, or it us. */
const STYLES = `
:host { all: initial; }
* { box-sizing: border-box; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; }
.launcher {
  position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;
  display: flex; align-items: center; gap: 8px;
  height: 40px; padding: 0 16px; border: 0; border-radius: 999px;
  background: #4f46e5; color: #fff; font-size: 13px; font-weight: 600;
  box-shadow: 0 6px 20px rgba(0,0,0,.25); cursor: pointer;
}
.launcher:hover { background: #4338ca; }
.panel {
  position: fixed; right: 20px; bottom: 20px; z-index: 2147483000;
  width: 380px; max-width: calc(100vw - 40px); max-height: calc(100vh - 40px);
  display: flex; flex-direction: column; overflow: hidden;
  background: #fff; color: #0f172a; border-radius: 14px;
  box-shadow: 0 20px 50px rgba(0,0,0,.3);
}
header { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid #e2e8f0; }
header strong { flex: 1; font-size: 13px; }
button.icon { border: 0; background: none; cursor: pointer; color: #64748b; font-size: 18px; line-height: 1; padding: 2px 6px; }
.body { padding: 14px; overflow-y: auto; }
label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
input, textarea {
  width: 100%; padding: 8px 10px; margin-bottom: 12px; font-size: 13px;
  border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #0f172a;
}
input:focus, textarea:focus { outline: 2px solid #4f46e5; outline-offset: -1px; border-color: #4f46e5; }
textarea { min-height: 80px; resize: vertical; }
.shot { position: relative; margin-bottom: 12px; }
canvas { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; cursor: crosshair; display: block; }
.tools { display: flex; gap: 6px; margin: 8px 0 0; align-items: center; }
.tool { border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; padding: 3px 9px; font-size: 11px; cursor: pointer; color: #475569; }
.tool[aria-pressed="true"] { border-color: #4f46e5; background: #eef2ff; color: #4f46e5; }
.hint { font-size: 11px; color: #64748b; margin: 6px 0 0; }
.shot { cursor: zoom-in; }
.shot canvas { pointer-events: none; }
.expand {
  position: absolute; right: 8px; top: 8px;
  display: flex; align-items: center; gap: 4px;
  padding: 4px 8px; border: 0; border-radius: 6px;
  background: rgba(15,23,42,.82); color: #fff; font-size: 11px; cursor: pointer;
}

/* Annotating a 1600px screenshot inside a 380px panel is unusable, so the editor
   takes over the viewport. */
.editor {
  position: fixed; inset: 0; z-index: 2147483001;
  display: flex; align-items: center; justify-content: center;
  padding: 72px 20px 20px; background: rgba(2,6,23,.88);
}
/* Width and height both auto, so the image keeps its shape as it is fitted; no
   border, so the drawing surface is exactly the element; and the page does not
   scroll or zoom under a finger that is trying to draw. */
.editor canvas {
  max-width: min(94vw, 1400px); max-height: calc(100vh - 100px);
  width: auto; height: auto; cursor: crosshair; border: 0;
  border-radius: 10px; box-shadow: 0 24px 60px rgba(0,0,0,.5);
  background: #fff; pointer-events: auto; touch-action: none;
}
/* The tools stay where they can always be seen and reached: the top right of the
   screen, not under an image that may run off the bottom of it. */
.editor-bar {
  position: fixed; top: 16px; right: 16px; z-index: 1;
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end;
  max-width: calc(100vw - 32px);
  padding: 8px 12px; border-radius: 999px; background: #fff;
  box-shadow: 0 8px 24px rgba(0,0,0,.3);
}
.editor-bar .tool { padding: 5px 12px; font-size: 12px; }
.editor-bar .hint { margin: 0; }
.editor-done {
  border: 0; border-radius: 999px; background: #4f46e5; color: #fff;
  padding: 6px 16px; font-size: 12px; font-weight: 600; cursor: pointer;
}
@media (prefers-color-scheme: dark) {
  .editor-bar { background: #0f172a; }
}
footer { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-top: 1px solid #e2e8f0; }
.submit { flex: 1; border: 0; border-radius: 8px; background: #4f46e5; color: #fff; padding: 9px; font-size: 13px; font-weight: 600; cursor: pointer; }
.submit:disabled { opacity: .5; cursor: default; }
.privacy { font-size: 10px; color: #94a3b8; padding: 0 14px 10px; line-height: 1.5; }
.done { padding: 30px 20px; text-align: center; }
.done h2 { margin: 0 0 6px; font-size: 15px; }
.done p { margin: 0; font-size: 13px; color: #64748b; }
.error { color: #dc2626; font-size: 12px; margin-bottom: 10px; }
@media (prefers-color-scheme: dark) {
  .panel { background: #0f172a; color: #e2e8f0; }
  header, footer { border-color: #1e293b; }
  input, textarea { background: #111c33; border-color: #334155; color: #e2e8f0; }
  .tool { background: #111c33; border-color: #334155; color: #94a3b8; }
  canvas { border-color: #1e293b; }
}
`;

export class Widget {
    private host: HTMLElement;
    private root: ShadowRoot;
    private open = false;
    private configLoaded = false;
    private canvas: HTMLCanvasElement | null = null;
    private tool: Tool = 'box';
    private annotatorAttached = false;

    identity: Identity = {};
    /** Set when the key is anonymous: later identify() calls are ignored too. */
    ignoreIdentity = false;
    release: string | null = null;

    constructor(private config: WidgetConfig) {
        this.host = document.createElement('div');
        this.host.setAttribute('data-buggie-widget', '');
        // Our own UI must never appear in a capture of the host page.
        this.host.setAttribute('data-buggie-redact', '');
        this.root = this.host.attachShadow({ mode: 'open' });

        const style = document.createElement('style');
        style.textContent = STYLES;
        this.root.appendChild(style);

        document.body.appendChild(this.host);

        if (this.config.launcher) {
            this.renderLauncher();
        }
    }

    private renderLauncher() {
        this.clear();

        const button = document.createElement('button');
        button.className = 'launcher';
        button.type = 'button';
        button.innerHTML = '<span aria-hidden="true">🐞</span> Report a bug';
        button.addEventListener('click', () => this.show());

        this.root.appendChild(button);
    }

    private clear() {
        for (const node of [...this.root.children]) {
            if (node.tagName !== 'STYLE') node.remove();
        }
    }

    /**
     * What the project says this widget should do.
     *
     * Fetched on first open rather than at page load: most visitors never report
     * anything, and the widget's whole argument is that it costs them nothing. By
     * the time the panel is opening, one small request is free.
     *
     * The `data-` attributes remain the fallback, so a blocked or slow request
     * leaves the reporter working rather than refusing to open. The server enforces
     * both settings at ingest regardless — this is for the form to say the right
     * thing, not for the rule to be kept.
     */
    private async loadConfig(): Promise<void> {
        if (this.configLoaded) return;

        this.configLoaded = true;

        try {
            const response = await fetch(
                `${this.config.endpoint}/api/ingest/${this.config.key}/config`,
                { credentials: 'omit' },
            );

            if (!response.ok) return;

            const settings = (await response.json()) as {
                require_email?: boolean;
                capture_screenshot?: boolean;
                mode?: string;
            };

            // A key set to anonymous takes no identity from the page: the reporter
            // types an email or stays anonymous. The server discards it anyway.
            if (settings.mode === 'anonymous') {
                this.identity = {};
                this.ignoreIdentity = true;
            }

            if (typeof settings.require_email === 'boolean') {
                this.config.requireEmail = settings.require_email;
            }

            if (typeof settings.capture_screenshot === 'boolean') {
                // The tag can switch a screenshot off, but never on: the project
                // deciding not to collect images is not a per-page choice.
                this.config.captureScreenshot =
                    this.config.captureScreenshot && settings.capture_screenshot;
            }
        } catch {
            // Offline, blocked by a CSP, or simply slow. The defaults stand.
        }
    }

    async show() {
        if (this.open) return;
        this.open = true;

        await this.loadConfig();

        this.clear();
        const panel = this.buildPanel();
        this.root.appendChild(panel);

        if (!this.config.captureScreenshot) return;

        // Capture once the panel is on screen, after a beat so our own UI is laid out
        // and hidden before html2canvas runs.
        //
        // Deliberately a timeout rather than requestAnimationFrame: rAF does not fire
        // in a hidden tab, and a report filed from a background window would sit on
        // "Capturing screenshot…" for ever.
        const slot = this.root.querySelector('.shot') as HTMLElement | null;
        if (!slot) return;

        await new Promise((resolve) => setTimeout(resolve, 50));
        const canvas = await capture(this.host);

        if (!canvas) {
            slot.innerHTML = '<p class="hint">Screenshot unavailable on this page.</p>';
            return;
        }

        this.canvas = canvas;
        slot.innerHTML = '';
        slot.appendChild(canvas);

        // The preview is a button into the editor; the drawing happens there, where
        // the image is big enough to aim at.
        const expand = document.createElement('button');
        expand.type = 'button';
        expand.className = 'expand';
        expand.textContent = '✎ Mark up';
        slot.appendChild(expand);

        const hint = document.createElement('p');
        hint.className = 'hint';
        hint.textContent = 'Tap the image to highlight a problem or hide anything private.';
        slot.appendChild(hint);

        const open = () => this.openEditor();
        expand.addEventListener('click', (e) => { e.stopPropagation(); open(); });
        slot.addEventListener('click', open);
    }

    /**
     * Full-screen annotation.
     *
     * The captured image is far larger than the panel, so marking it up in place means
     * aiming at a thumbnail. The same canvas element moves into an overlay, scaled to
     * the viewport, and moves back when done — so there is only ever one image and no
     * copying between them.
     */
    private openEditor() {
        if (!this.canvas || this.root.querySelector('.editor')) return;

        const canvas = this.canvas;
        const slot = this.root.querySelector('.shot') as HTMLElement;

        const editor = document.createElement('div');
        editor.className = 'editor';
        editor.setAttribute('role', 'dialog');
        editor.setAttribute('aria-label', 'Mark up the screenshot');

        const bar = document.createElement('div');
        bar.className = 'editor-bar';

        const buttons: HTMLButtonElement[] = [];

        for (const [tool, label] of [
            ['box', 'Highlight'],
            ['blur', 'Hide'],
        ] as const) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tool';
            button.textContent = label;
            button.setAttribute('aria-pressed', String(this.tool === tool));
            button.addEventListener('click', () => {
                this.tool = tool;
                buttons.forEach((b) =>
                    b.setAttribute('aria-pressed', String(b.textContent === label)),
                );
            });
            buttons.push(button);
            bar.appendChild(button);
        }

        const hint = document.createElement('span');
        hint.className = 'hint';
        hint.textContent = 'Drag on the image.';
        bar.appendChild(hint);

        const done = document.createElement('button');
        done.type = 'button';
        done.className = 'editor-done';
        done.textContent = 'Done';
        bar.appendChild(done);

        editor.appendChild(canvas);
        editor.appendChild(bar);
        this.root.appendChild(editor);

        const close = () => {
            // Put the image back in the panel so the reporter still sees what they
            // are about to send.
            slot.insertBefore(canvas, slot.firstChild);
            editor.remove();
            document.removeEventListener('keydown', onKey);
        };

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close();
            }
        };

        done.addEventListener('click', close);
        editor.addEventListener('click', (event) => {
            // Clicking the backdrop closes; clicking the image draws.
            if (event.target === editor) close();
        });
        document.addEventListener('keydown', onKey);

        // Listeners live on the canvas, which outlives the overlay — attaching on
        // every open would draw one rectangle per time it had been opened.
        if (!this.annotatorAttached) {
            attachAnnotator(canvas, () => this.tool);
            this.annotatorAttached = true;
        }
    }

    /** Close the panel from outside — the host app's own cancel affordance. */
    dismiss() {
        this.hide();
    }

    private hide() {
        this.open = false;
        this.canvas = null;
        this.annotatorAttached = false;

        if (this.config.launcher) {
            this.renderLauncher();
        } else {
            // Nothing of ours should remain on the page between reports.
            this.clear();
        }
    }

    private buildPanel(): HTMLElement {
        const panel = document.createElement('div');
        panel.className = 'panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Report a bug');

        panel.innerHTML = `
          <header>
            <strong>Report a bug</strong>
            <button class="icon" type="button" aria-label="Close">&times;</button>
          </header>
          <div class="body">
            <p class="error" hidden></p>
            <label for="buggie-title">What went wrong?</label>
            <input id="buggie-title" maxlength="200" placeholder="The checkout button does nothing" />
            <label for="buggie-body">Anything else?</label>
            <textarea id="buggie-body" maxlength="4000" placeholder="What you expected instead, and how to reproduce it."></textarea>
            ${
                // An email from identify() is the page vouching for who this is, so
                // the reporter is not asked to type it again.
                !this.identity.email
                    ? `<label for="buggie-email">Your email${this.config.requireEmail ? '' : ' (optional)'}</label>
                       <input id="buggie-email" type="email" value="${escapeAttribute(this.identity.email ?? '')}" placeholder="you@example.com" />`
                    : ''
            }
            <div class="shot">${this.config.captureScreenshot ? '<p class="hint">Capturing screenshot…</p>' : ''}</div>
          </div>
          <footer>
            <button class="submit" type="button">Send report</button>
          </footer>
          <p class="privacy">
            We attach the page address, your browser version, recent console messages and
            the timing of recent requests. Passwords and hidden fields are never captured,
            and you can hide anything else on the image before sending.
          </p>
        `;

        panel.querySelector('.icon')?.addEventListener('click', () => this.hide());
        panel.querySelector('.submit')?.addEventListener('click', () => this.submit(panel));

        return panel;
    }

    private async submit(panel: HTMLElement) {
        const title = (panel.querySelector('#buggie-title') as HTMLInputElement)?.value.trim();
        const body = (panel.querySelector('#buggie-body') as HTMLTextAreaElement)?.value.trim();
        const email = (panel.querySelector('#buggie-email') as HTMLInputElement | null)?.value.trim();
        const error = panel.querySelector('.error') as HTMLElement;
        const submit = panel.querySelector('.submit') as HTMLButtonElement;

        const fail = (message: string) => {
            error.textContent = message;
            error.hidden = false;
            submit.disabled = false;
            submit.textContent = 'Send report';
        };

        if (!title) return fail('Please describe what went wrong.');
        if (this.config.requireEmail && !email) return fail('Please leave an email address.');

        error.hidden = true;
        submit.disabled = true;
        submit.textContent = 'Sending…';

        const captured = getError();

        try {
            const response = await fetch(`${this.config.endpoint}/api/ingest/${this.config.key}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // Never send the reporter's cookies to us.
                credentials: 'omit',
                body: JSON.stringify({
                    title,
                    body: body || null,
                    reporter: {
                        name: this.identity.name ?? null,
                        email: this.identity.email || email || null,
                        ref: this.identity.id != null ? String(this.identity.id) : null,
                        // Where the email came from, which the server cannot otherwise
                        // tell: the page, via identify(), or the reporter's own typing.
                        source: this.identity.email ? 'identify' : email ? 'typed' : null,
                        user_hash: typeof this.identity.user_hash === 'string' ? this.identity.user_hash : null,
                    },
                    environment: { ...environment(this.release), identity: scrubIdentity(this.identity) },
                    console: getConsole(),
                    network: getNetwork(),
                    error: captured,
                    screenshot: Boolean(this.canvas),
                }),
            });

            if (!response.ok) {
                // The server's message is usually more useful than ours — a quota
                // problem is not the reporter's fault and should not read as one.
                const problem = await response.json().catch(() => null);

                return fail(
                    problem?.message ??
                        (response.status === 429
                            ? 'Too many reports just now. Please try again shortly.'
                            : 'Could not send the report. Please try again.'),
                );
            }

            const result = await response.json();

            if (result.upload_url && this.canvas) {
                await this.upload(result.upload_url, this.canvas);
            }

            this.showThanks(panel, result.reference);
        } catch {
            fail('Could not reach the reporting service.');
        }
    }

    /** The image goes straight to its own signed endpoint, not through the JSON body. */
    private async upload(url: string, canvas: HTMLCanvasElement) {
        const blob = await toBlob(canvas);
        if (!blob) return;

        const form = new FormData();
        form.append('screenshot', blob, 'screenshot.jpg');

        try {
            await fetch(url, { method: 'POST', body: form, credentials: 'omit' });
        } catch {
            // A missing screenshot is not worth failing an otherwise good report.
        }
    }

    private showThanks(panel: HTMLElement, reference?: string) {
        panel.innerHTML = `
          <div class="done">
            <h2>Thanks — that helps.</h2>
            <p>${reference ? `Your reference is <strong>${escapeHtml(reference)}</strong>.` : 'Your report has been sent.'}</p>
          </div>
        `;

        setTimeout(() => this.hide(), 2600);
    }
}

/** Only ever send the identity fields the host app chose to give us. */
function scrubIdentity(identity: Identity): Record<string, unknown> {
    // The hash is a credential, not context: it is checked once and never stored.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { id, email, name, user_hash, ...rest } = identity;

    return { id: id ?? null, email: email ?? null, name: name ?? null, ...rest };
}

function escapeHtml(value: string): string {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}

function escapeAttribute(value: string): string {
    return escapeHtml(value).replace(/"/g, '&quot;');
}
