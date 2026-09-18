# Privacy and security

The widget runs inside *your customers'* applications, on *their users'* screens. It
is therefore deliberately boring. This page is written to be the answer when a
client's security reviewer asks what that script can see.

## What leaves a visitor's browser

Attached to a report:

- The page address, the referrer and the page title.
- The user agent, language, time zone, viewport size, screen size and device pixel
  ratio.
- The release string, if the host application set one.
- The last 50 console entries, each trimmed to 300 characters.
- The last 30 network calls: **method, URL, status and duration only**.
- The most recent uncaught error or unhandled rejection, with its stack.
- Whatever the host application passed to `identify()`.
- A screenshot of the viewport, if the reporter chose to send one.
- Whatever the reporter typed.

## What never leaves it

- **Cookies.** `document.cookie` is never read, and the report is posted with
  `credentials: 'omit'`, so the visitor's cookies are not sent to Buggie either.
- **Local storage.** Never read, apart from the widget's own opt-in preference.
- **Request and response headers and bodies.** Only the four fields above are kept
  from a network call.
- **Password fields.** Masked before the screenshot is taken.
- **Anything marked private.** `[data-buggie-redact]` and `.buggie-redact` are masked
  the same way.
- **Credential-shaped query parameters.** `token`, `key`, `secret`, `password`,
  `passwd`, `auth`, `session`, `sig`, `signature`, `access_token`, `api_key` and
  `code` have their values replaced with `[redacted]` in every captured URL, and URL
  fragments are dropped entirely, because OAuth flows park tokens there. The same
  stripping is repeated on the server, because the endpoint cannot assume a payload
  came from the widget.

## Why the redaction is trustworthy

Masks are applied by **restyling elements in the DOM before the page is rasterised**,
not by painting rectangles over the finished image. Painting afterwards has to
reproduce the renderer's coordinate system exactly, and when it disagreed — which it
did, in testing — the masks landed on the field labels while the password stayed
perfectly readable, with nothing anywhere to say so.

Letting the browser lay out the mask cannot be misaligned. **A redaction mechanism
that can be silently misaligned is not a redaction mechanism.** The same rule is
enforced in the [iOS and Android SDKs](native-sdks.md), where it caught a real defect
that would have put a password field in every capture.

## The reporter is always shown the image

The screenshot is displayed in the panel before anything is sent, with a full-screen
editor offering **Highlight** and **Hide**. *Hide* pixelates rather than blurs,
because a blur can be reversed by sharpening. Nothing is uploaded until **Send
report** is pressed.

## The ingest endpoint

The widget key is public — it sits in your page source — so the endpoint is
unauthenticated by necessity and is treated as hostile input:

- **Origin allowlist** per key.
- **Rate limits**: 5 per minute per IP address per key, 200 per hour per key.
- **Hard size caps** on every field, and a 2 MB ceiling on the image, which is
  uploaded to a separate signed, single-use URL valid for five minutes.
- **No IP addresses are stored.** The reporter's IP is kept only as an HMAC, which is
  enough to rate-limit and not enough to identify.

## Tenancy

Every tenant-owned table carries a workspace id, and every model that uses one filters
on it automatically. Querying such a model with no workspace bound throws rather than
returning every customer's rows, and a test asserts that every table with a workspace
id has a model doing this.

Someone visiting a workspace they do not belong to gets a **404, not a 403** — a 403
would confirm the workspace exists, which is a free way to enumerate customer names.

## Client isolation

A client sees an issue only when three separate conditions hold: workspace membership
as a client, a grant on that specific project, and `visible to client` on that
individual issue. See [Clients](clients.md).

Beyond issues, everything a client could learn something from is scoped: the
dashboard, the project list, the label list, the filter bar's choices, saved views,
activity events and comments. A client is not shown another customer's project name,
which in a shared agency workspace is itself a leak.

## Attachments

- An **allowlist** of types: PNG, JPEG, GIF, WebP, PDF, plain text, CSV, JSON, ZIP.
  Maximum 10 MB.
- **No SVG.** It is an XML document that can carry script, and attachments are served
  from Buggie's own origin.
- The uploaded filename is kept as a label only; the stored path is generated, so a
  crafted name cannot escape the directory.
- Every read goes through the issue's own visibility check. These files routinely hold
  a customer's production data.
- Responses carry `X-Content-Type-Options: nosniff`. Images render inline; everything
  else is forced to download under a strict content security policy.

## Tokens

- **Invitation** tokens: 48 characters, 14 days, one live token per address per
  workspace.
- **Reporter portal** tokens: 48 characters, 90 days, bound to a single issue, replies
  rate limited to ten per ten minutes.
- **Password reset** links work once; requesting one for an unknown address produces
  exactly the response a known one does.
- **Project inbound email** tokens are random rather than derived from the slug, so
  guessing one project's address does not reveal another's. A reply token pairs the
  issue key with the project token, so a valid key cannot be aimed at another project.
- Inbound mail is rejected unless it carries a valid Mailgun signature less than five
  minutes old, and is rejected entirely if no signing key is configured.

## Retention

Bug reports collect personal data as a side effect of being useful: a picture of
whatever was on somebody's screen, the address they wrote from, the account they were
signed in as. A scheduled job, `buggie:prune`, runs daily and ages it out.

| What | Default |
|---|---|
| Screenshots deleted; the report keeps its title and error | 180 days |
| Reporter name, address, reference and IP hash scrubbed, including the identity block inside the captured environment | 180 days |
| Spam and discarded reports deleted outright | 30 days after triage |
| Expired portal links deleted | 30 days after expiry |
| **Issues and comments** | **Never pruned** |

Issues and comments are the work product; a tracker that deletes its own history is
not a tracker.

Each window is a day count in configuration (`RETAIN_SCREENSHOT_DAYS`,
`RETAIN_REPORTER_DAYS`, `RETAIN_DISMISSED_DAYS`, `RETAIN_EXPIRED_TOKENS_DAYS`), and
setting one to zero disables that rule. They are install-wide: there is no per-workspace
retention setting. `buggie:prune --dry-run` reports what would go without deleting
anything.

Pruning deliberately runs across every workspace at once — it is housekeeping, not a
tenant operation.

## No telemetry

Nothing in Buggie phones home in either mode: no licence check, no usage ping, no
error reporting unless an operator sets their own Sentry DSN. On a self-hosted install
there is no billing information in the front end at all, so the interface has nothing
to nag with. A test asserts this.

## Related pages

- [The reporter widget](widget.md)
- [Clients](clients.md)
- [Self-hosting](self-hosting.md)
