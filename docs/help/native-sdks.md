# Native SDKs

Both native SDKs post to the same `POST /api/ingest/{public_key}` endpoint as the web
[widget](widget.md), authenticated by the same public widget key from project
settings. No server configuration differs: reports from a phone land in the same
[triage inbox](triage.md), and two native reports of the same bug share a fingerprint
and group exactly as browser reports do.

Neither SDK ships a report sheet. What a bug report looks like inside somebody's
application is their decision, and a sheet shipped here would be the first thing they
had to fight. Each gives you a screen capture and a `report(…)` call; the interface is
yours.

Both are split into two parts on purpose:

| | |
|---|---|
| Core | The payload, the redaction, the ring buffers, the transport. No platform imports, so it can be tested anywhere. |
| Platform | Screen capture and device details. Needs a device. |

The code that decides *what leaves a phone* is the code that must be tested, and
putting it behind a platform import would mean it could only be tested where that
platform's SDK exists.

## iOS — `packages/swift`

A Swift package, requiring iOS 15.

```swift
import Buggie

// Once, at launch.
Buggie.shared.start(key: "pk_live_…")

// Self-hosting:
Buggie.shared.start(key: "pk_live_…", endpoint: URL(string: "https://bugs.acme.com")!)
```

Sending a report:

```swift
let screenshot = Buggie.shared.captureScreen()

try await Buggie.shared.report(
    title: "Pay now does nothing",
    body: notes,
    reporterEmail: email,
    screenshot: screenshot
)
```

Context:

```swift
Buggie.shared.identify(id: String(user.id), email: user.email, name: user.name)
Buggie.shared.setRelease("2.4.1")

Buggie.shared.leaveBreadcrumb("Tapped Pay now")
Buggie.shared.record(method: "POST", url: url, status: 500, duration: 0.41)
do { try pay() } catch { Buggie.shared.record(error: error) }
```

Breadcrumbs and requests are kept in fixed buffers of 50 and 30, matching the web
widget.

Redaction:

```swift
cardNumberField.buggieRedacted = true
```

Marked views are hidden **before** the screen is rendered, and `UITextField`s with
`isSecureTextEntry` are included automatically. Request headers and bodies are never
sent, credential-shaped query parameters are replaced, and URL fragments are dropped —
the same list of parameter names as the web widget, tested on both sides.

Refusals map to distinct cases rather than a status code, because "this account is out
of reports" (402) and "slow down" (429) call for different behaviour and neither is
the reporter's fault.

Swift Package Manager insists on finding `Package.swift` at a repository root, so this
cannot be consumed from `packages/swift` in the monorepo. It needs a `buggie-swift`
mirror repository, which is why the install instructions name one.

**What is not verified:** the UIKit layer cannot be compiled without an iOS SDK, so it
is syntax-checked rather than type-checked unless Xcode is present, and no report has
yet been filed from a physical phone. The wire format, the redaction logic and the
transport have been driven against a running Buggie.

## Android — `packages/kotlin`

A Kotlin SDK, requiring Android 7 (API 24), with the same shape.

```kotlin
// Once, in Application.onCreate.
Buggie.start(this, key = "pk_live_…")
Buggie.start(this, key = "pk_live_…", endpoint = "https://bugs.acme.com")
```

```kotlin
val screenshot = Buggie.captureScreen()   // on the main thread

Buggie.reportInBackground(
    title = "Pay now does nothing",
    body = notes,
    reporterEmail = email,
    screenshot = screenshot,
) { result ->
    result.onSuccess { toast("Sent — ${it.reference}") }
    result.onFailure { toast(it.message ?: "Could not send") }
}
```

`Buggie.report(…)` is the same call, blocking, for code that already has a thread.

Context and refusals mirror the iOS package:

```kotlin
Buggie.identify(id = user.id.toString(), email = user.email, name = user.name)
Buggie.setRelease("2.4.1")
Buggie.leaveBreadcrumb("Card declined", Breadcrumb.Level.ERROR)
Buggie.record(method = "POST", url = url, status = 500, durationMillis = 410)
```

```kotlin
when (val error = result.exceptionOrNull()) {
    is BuggieException.QuotaReached -> show(error.serverMessage)
    is BuggieException.RateLimited -> retryIn(error.retryAfter)
    else -> show("Could not send the report.")
}
```

Redaction, in code or in XML:

```kotlin
cardNumberField.buggieRedacted = true
```

```xml
<EditText android:tag="buggie-redact" … />
```

Fields whose `inputType` carries a password variation are redacted automatically.

The capture uses `View.draw(Canvas)` rather than `PixelCopy`, and the reason is worth
repeating: `PixelCopy` reads the buffer the compositor has **already produced**, so
hiding a view and then copying can return an image with the password still in it.
`View.draw` walks the hierarchy synchronously in the same call stack, with no frame in
between. The cost is that content the view system does not draw itself —
`SurfaceView`, `TextureView`, video, dialogs, the system bars — is absent. A
screenshot missing content is a poor screenshot; a screenshot containing a password is
an incident.

The SDK has no dependencies of its own: not a JSON library, not an HTTP client, not
coroutines. It is compiled into other people's applications, where every library it
insisted on would be a version they may already be fighting over.

**Availability:** the SDK lives in this repository and is tested there, but the module
has no publishing configuration, so the Gradle coordinate in its README does not
resolve from a public repository yet. Build it from source for now.

Note also that `docs/DESIGN.md` §25 states there is no Android SDK, and
`README.md` says the same. Both predate this package.

## Related pages

- [The reporter widget](widget.md) — the same capture rules, in a browser
- [Triage](triage.md) — where native reports land
- [Privacy and security](privacy-and-security.md)
