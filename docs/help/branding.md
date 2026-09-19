# White-labelling

Two places in Buggie are seen by people who are not on your team: the **reporter
portal**, where somebody who filed a bug checks what happened to it, and the
**reporter widget** itself, sitting in your client's application. Both can carry your
client's name and colours instead of Buggie's.

This is set **per project**, not per workspace. An agency runs one workspace and many
clients, and each client's site should look like theirs — not like the agency's, and
not like everyone else's.

## Setting it

**Project → Settings → Branding**. Three fields:

| Field | Effect | Falls back to |
|---|---|---|
| **Brand name** | The name on the portal and the widget's panel | The project name |
| **Brand colour** | Buttons and accents in both | Buggie's accent |
| **Logo** | Shown on the portal and above the widget's form | Nothing — the name alone |

Leave any of them blank and that part is simply not branded. A project nobody has
touched looks deliberate rather than half-configured.

**The colour must be a six-digit hex value** — `#1d4ed8`, not `red` and not
`rgb(29,78,216)`. This value ends up inside a `style` attribute, and "anything goes"
there is how a stylesheet becomes a script tag. Anything else is rejected by the form.

**The logo** may be a PNG, JPEG, WebP or SVG, up to 512 KB. Replacing one deletes the
old file; there is no history of logos a client used two rebrands ago.

## What it does not change

- **The application itself.** Your team still sees Buggie: the issue list, the board,
  the triage inbox. Branding is for the surfaces your client's users see.
- **Email.** Notifications come from your instance's configured sender. See
  [Email](email.md).
- **The domain.** The portal is served from your Buggie domain. There is no custom
  domain per project.
- **Anything a signed-in client sees.** A client invited to the workspace is inside
  the application, not on the portal. See [Clients](clients.md).

## How the logo is served

Not from public storage. The logo is delivered by the application at
`/brand/{project}/logo`, which means:

- It is reachable without an account, because the portal is — anybody holding a
  report link can see it.
- It is looked up by numeric id, not by project slug. Slugs are unique within a
  workspace rather than globally: two agencies may each have a project called
  "website".
- It is sent with `X-Content-Type-Options: nosniff` and a restrictive
  `Content-Security-Policy` including `sandbox`. **An uploaded SVG is a document that
  can carry script**, so it is served as an image and never executed as a page.

An id is guessable, and guessing one reveals that a project exists and has a logo.
That is already visible to anybody with a portal link, so there is no machinery around
it.

## In the widget

The widget fetches its branding from the same configuration endpoint it already calls
on load, so **changing branding takes effect without redeploying the client's site**.
There is nothing to re-paste into their HTML.

See [The reporter widget](widget.md) for installing it, and
[Clients](clients.md#the-reporter-portal) for what a reporter sees afterwards.
