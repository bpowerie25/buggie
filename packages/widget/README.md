# @buggie/widget

Typed loader for the [Buggie](https://buggie.eu) bug reporter.

Bug reports arrive with the screenshot, the console, the failing request, the route,
the release and whoever was signed in — so nobody has to ask "what browser were you
on?".

```sh
npm i @buggie/widget
```

```ts
import { init } from '@buggie/widget';

init({ key: 'pk_live_9f3a2b' });
```

That's it. A floating "Report a bug" button appears, and reports land in your triage
inbox.

## Your own button

```ts
import { init, open } from '@buggie/widget';

init({ key: 'pk_live_9f3a2b', launcher: false });

// …from a menu item, a keyboard shortcut, an error boundary:
<button onClick={() => open()}>Report a bug</button>
```

## Attributing reports

```ts
init({
    key: 'pk_live_9f3a2b',
    release: import.meta.env.VITE_COMMIT_SHA,
    identity: { id: user.id, email: user.email, name: user.name },
});
```

Or later, once you know who they are:

```ts
import { identify } from '@buggie/widget';

identify({ id: user.id, email: user.email, name: user.name });
```

## Self-hosting

```ts
init({ key: 'pk_live_9f3a2b', endpoint: 'https://bugs.yourcompany.com' });
```

## React

```tsx
import { init } from '@buggie/widget';
import { useEffect } from 'react';

export function BugReporter({ user }: { user?: { id: number; email: string } }) {
    useEffect(() => {
        init({ key: 'pk_live_9f3a2b', identity: user });
    }, [user]);

    return null;
}
```

Next.js, Remix and other server-rendered frameworks are fine: nothing touches the DOM
until `init()` runs, and `init()` resolves to `null` on the server rather than throwing.

## API

| | |
|---|---|
| `init(options)` | Loads the widget. Safe to call repeatedly — later calls reuse the first. |
| `identify(identity)` | Attach who is using the app. |
| `setRelease(release)` | Tag reports with a build. |
| `open()` / `close()` | Show or hide the reporter. |
| `isSupported()` | Whether it can run here — false while server rendering. |
| `reset()` | Tear down. For tests and hot reloads. |

### Options

| Option | Default | |
|---|---|---|
| `key` | — | Required. From your project settings. |
| `endpoint` | `https://buggie.eu` | Your Buggie instance. |
| `launcher` | `true` | Draw the floating button. |
| `screenshot` | `true` | Offer a screenshot. |
| `requireEmail` | `false` | Demand an address before sending. |
| `release` | — | Build identifier. |
| `identity` | — | Set immediately, saving an `identify()` call. |

## What it collects, and what it never does

Attached: the page address, browser and viewport, recent console messages, the method,
URL, status and timing of recent requests, any unhandled error with its stack, and
whatever you passed to `identify()`.

Never: cookies, `localStorage`, request or response bodies, password fields, or
anything marked `data-buggie-redact` — those are masked **before** the screenshot is
taken, not painted over afterwards. Credential-shaped query parameters are stripped
from every captured URL, and the reporter sees the image, and can hide anything else
on it, before sending.

If the script is blocked by a strict CSP or the visitor is offline, `init()` resolves
to `null` and your application carries on. Reporting is a nicety; the app is not.

## Licence

AGPL-3.0-only, the same as Buggie.
