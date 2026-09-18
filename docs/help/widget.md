# The reporter widget

The widget is the point of Buggie. It is a small script that sits in your application
and, when somebody clicks "Report a bug", sends a report already carrying the
screenshot, the recent console output, the recent network calls, the last error, the
page address, the release and whoever was signed in.

Reports land in the [triage inbox](triage.md), not the backlog.

## Installing it

Create a widget key in **Project settings → Bug reporter widget**
(`/projects/{slug}/edit`). The screen shows the snippet, ready to copy:

```html
<script src="https://buggie.eu/w/pk_9f3a2bc41d7e08a5b6c3d2e1.js" async></script>
```

On a self-hosted install the host is your own domain. That is the entire
installation — the bundle reads its own script URL to find the key, so every key
serves the identical, cacheable file and there is nothing to configure.

Keys look like `pk_` followed by 24 lowercase characters. The key is public by
necessity: it sits in your page source. What protects it is the
[origin allowlist](#the-origin-allowlist) and the rate limits, not secrecy.

### Script tag attributes

| Attribute | |
|---|---|
| `data-launcher="false"` | Do not draw the floating button. Call `buggie.open()` from your own menu, shortcut or error boundary. |
| `data-launcher="opt-in"` | Draw the button only for browsers that have visited `?buggie=on`. |
| `data-screenshot="false"` | Do not offer a screenshot at all. |
| `data-require-email="true"` | Demand an email address before a report can be sent. |

An unrecognised `data-launcher` value shows the button, so a typo leaves reporting
working rather than silently removing it from a client's site.

### Who sees the button

An agency putting Buggie on a client's live shop does not want the shop's customers
looking at a "Report a bug" button. Three answers, in order of how well they work:

1. **Do not render the script tag for customers at all.** Nothing loads and nothing
   can be turned on. Needs a conditional in the template.
2. **`data-launcher="false"`** and a trigger of your own, in an admin menu or a help
   menu. Needs their developer to wire something up.
3. **`data-launcher="opt-in"`** — no button until someone visits the site with
   `?buggie=on`. The preference is remembered in that browser's local storage and
   `?buggie=off` clears it. The parameter is stripped from the address bar
   afterwards, so the switch does not travel into a shared link or an analytics
   report. This needs nothing from their developer, which is the point.

Option 3 hides the interface; it does not restrict it. The key is in the page source
either way, so a customer who goes looking could turn it on. It is a presentation
choice, not a security boundary — the origin allowlist and not serving the tag are
what actually restrict.

## The JavaScript API

`window.buggie` is available once the script has loaded:

```js
window.buggie.identify({ id: 4821, email: 'ann@acme.com', name: 'Ann', plan: 'pro' });
window.buggie.setRelease('2026.09.18-a1c3');

window.buggie.open();        // show the reporter
window.buggie.close();       // hide it
window.buggie.isSupported(); // false where shadow DOM is unavailable
window.buggie.enable();      // opt this browser in, for data-launcher="opt-in"
window.buggie.disable();     // opt it out again
```

Because the tag is `async`, calls made before it has loaded would otherwise be lost.
Queue them instead, and they are replayed in order once the widget boots:

```js
window.buggie = window.buggie || { q: [] };
window.buggie.q.push(['identify', { id: 4821, email: 'ann@acme.com' }]);
window.buggie.q.push(['setRelease', '2026.09.18-a1c3']);
```

`identify()` sends exactly what you pass it and nothing else — `id`, `email`, `name`
and any extra keys of your own. Nothing is scraped from the page.

## What it captures

| | |
|---|---|
| **Screenshot** | The viewport, rendered with html2canvas, scaled so the widest edge is at most 1600px, exported as JPEG at quality 0.8. Optional, and always shown to the reporter before sending. |
| **Console** | The last 50 entries across `log`, `info`, `warn`, `error` and `debug`. Each message is trimmed to 300 characters. The original `console` method is always called through. |
| **Network** | The last 30 `fetch` and `XMLHttpRequest` calls: method, URL, status (or `failed`), and duration in milliseconds. **Headers and bodies are never captured.** |
| **Errors** | The most recent uncaught error or unhandled promise rejection, with its stack. Message trimmed to 2000 characters, stack to 8000. |
| **Environment** | Page URL, referrer, page title, user agent, language, time zone, viewport size, screen size, device pixel ratio, release, and the capture time. |
| **Identity** | Whatever `identify()` was given. |

The buffers are installed the moment the script runs, because the interesting things
happen well before anybody thinks to click "report a bug".

## What it never does

- It never reads `document.cookie` or `localStorage` (other than its own `?buggie=on`
  preference).
- It never captures request or response headers or bodies.
- It never sends your visitors' cookies to Buggie — the request is made with
  `credentials: 'omit'`.
- It never captures password fields or anything you have marked as private.

### Redaction

Three things are masked before the screenshot is taken:

```html
<input type="password">
<div data-buggie-redact>…</div>
<div class="buggie-redact">…</div>
```

The masking works by **restyling those elements in the DOM before rasterising** —
solid block, transparent text, children hidden — then undoing it afterwards. The
obvious alternative, painting rectangles onto the finished canvas, has to reproduce
the renderer's coordinate system exactly, and when it disagreed the masks landed on
the field labels while the password stayed perfectly readable, with nothing on screen
to say so. Letting the browser lay out the mask cannot be misaligned. A redaction
mechanism that can be silently misaligned is not a redaction mechanism.

The widget's own panel is marked `data-buggie-redact`, so it never appears in a
capture of your page.

### URL redaction

Every URL that leaves the page — the page address, the referrer, and every captured
network call — has credential-shaped query parameters replaced with `[redacted]`, and
its fragment removed entirely, because OAuth flows park tokens there.

The parameter names stripped are `token`, `key`, `secret`, `password`, `passwd`,
`auth`, `session`, `sig`, `signature`, `access_token`, `api_key` and `code`. The same
stripping is repeated on the server, because the endpoint cannot assume a payload came
from the widget.

## The reporter's experience

Clicking the button opens a small panel with:

- **What went wrong?** — required, up to 200 characters in the field.
- **Anything else?** — optional, up to 4000 characters.
- **Your email** — shown when the widget cannot already infer one from `identify()`,
  or always when `data-require-email="true"`, in which case it is required.
- The screenshot, once captured.
- A privacy line stating what is attached.

### Annotating the screenshot

The preview is a button into a full-screen editor, because marking up a 1600px
screenshot inside a 380px panel means aiming at a thumbnail. Two tools:

- **Highlight** — a red rectangle around the thing that is wrong. This is the single
  highest-value part of the whole capture.
- **Hide** — pixelates a region. Pixelation rather than a blur, because a blur can be
  reversed by sharpening.

Escape or **Done** returns to the panel with the marked-up image in place. Nothing is
uploaded until the reporter presses **Send report**.

If html2canvas cannot be fetched — a strict `Content-Security-Policy`, an offline
visitor, a slow CDN — the panel says the screenshot is unavailable and the report is
sent without one. There is a six-second ceiling on that load, so a blocked CDN gives
a report with no image, never a stuck panel.

On success the reporter is thanked and given a reference of the form `R-1234`.

## What happens on the server

The report is posted to `POST /api/ingest/{public_key}` on the central domain, which
is unauthenticated by necessity and therefore treated as hostile input:

- **Origin allowlist** — see below. A mismatch is a `403`.
- **Rate limits** — 5 reports per minute per IP address per key, and 200 per hour per
  key. Over either, a `429` with `Retry-After`.
- **Size caps** — title 255 characters, body 5000, at most 50 console entries and 30
  network entries, error message 2000 and stack 8000.
- **Quota** — on the hosted service, a workspace over its monthly report limit gets a
  `402` carrying a message the widget displays to the reporter. The person who hit the
  bug did nothing wrong and should be told something true rather than "could not
  send". See [Billing and plans](billing.md).
- **Unknown or deactivated key** — a `404`, indistinguishable from a key that never
  existed.

A successful post returns `202` immediately with the report reference. Everything
expensive — fingerprinting, grouping, notifying — happens on the queue, because the
person who just hit a bug is waiting on that response.

The screenshot is **not** in that request. The response carries a signed, single-use
upload URL valid for five minutes, which the widget posts the image to separately.
Images must be JPEG, PNG or WebP and at most 2 MB. A second upload against the same
report is refused.

The reporter's IP address is stored only as an HMAC, never in the clear.

## The origin allowlist

Each widget key holds a list of origins allowed to post with it. Entries may be
exact, `https://acme.com`, or a wildcard subdomain, `https://*.acme.com`. One per
line in project settings.

**An empty list accepts any origin.** That is the only workable default for a
paste-this-snippet install, and the settings screen says so. A new key is seeded from
the project's [site URL](projects.md#site-url) — both the apex and the `www` form —
so a project with a site URL set starts locked down.

## Per-key settings

In project settings, each key has:

- **Allowed origins**, as above.
- **Offer a screenshot** — when off, the server issues no upload URL, so no image is
  stored.
- **Require an email address** — see the note below.
- **Accepting reports** — turning this off makes the key behave as though it does not
  exist. **Revoke** deletes it outright.

The screen also shows when the key was last used.

> **Worth knowing:** the widget reads "require an email address" and "offer a
> screenshot" from the `data-` attributes on the script tag, not from these
> checkboxes. Turning *Require an email address* on in settings does not currently
> change what the reporter sees; use `data-require-email="true"` in the snippet.
> Turning *Offer a screenshot* off does stop the image being stored, but the reporter
> is still shown a screenshot to annotate first. The `mode` field (anonymous or
> identified) recorded against a key has no effect anywhere.

## The npm package

For bundled front ends there is `@buggie/widget`, a typed **loader** rather than a
copy of the widget:

```sh
npm i @buggie/widget
```

```ts
import { init } from '@buggie/widget';

init({ key: 'pk_9f3a2bc41d7e08a5b6c3d2e1', endpoint: 'https://buggie.eu' });
```

It injects the script your server already serves at `/w/{key}.js`. That is the whole
design decision, and it matters for something self-hostable: the widget a visitor runs
always matches the server it reports to, so upgrading Buggie upgrades every
customer's widget without anyone redeploying a front end.

Options: `key` (required), `endpoint` (defaults to `https://buggie.eu`), `launcher`,
`screenshot`, `requireEmail`, `release`, `identity`. Exports: `init`, `identify`,
`setRelease`, `open`, `close`, `isSupported`, `reset`.

Three behaviours to rely on:

- **Nothing touches the DOM at import time.** `init()` resolves to `null` during
  server rendering rather than throwing, so Next and Remix need no guard.
- **`init()` is idempotent.** Calling it twice — easy in a React effect — reuses the
  first promise instead of injecting a second script.
- **A blocked script resolves to `null`.** A strict CSP or an offline visitor must not
  break the host application.

The package's `launcher` option is a boolean; the `opt-in` mode is available only
through `data-launcher="opt-in"` on a raw script tag. The package has not been
published to npm yet — it is built from `packages/widget` in this repository.

## Content-Security-Policy

A host application with a strict CSP needs `connect-src` to allow your Buggie domain
and `script-src` to allow the `/w/` URL. Screenshot capture additionally fetches
html2canvas from `cdnjs.cloudflare.com`; if that is blocked, reports still arrive,
without an image.

## Related pages

- [Triage](triage.md) — where reports go
- [Privacy and security](privacy-and-security.md)
- [Native SDKs](native-sdks.md) — iOS and Android
