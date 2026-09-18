# Buggie for Android

Report bugs from an Android app into [Buggie](https://buggie.eu), carrying the same
context the web widget does: recent logs, recent requests, the last error, a screenshot,
and what the device can say about itself.

Requires Android 7 (API 24). AGPL-3.0, like the rest of Buggie.

## Install

```kotlin
dependencies {
    implementation("eu.buggie:buggie-android:0.1.0")
}
```

The source of truth is `packages/kotlin` in the [main Buggie
repository](https://github.com/bpowerie25/buggie).

The SDK has no dependencies of its own — not a JSON library, not a HTTP client, not
coroutines. It is compiled into other people's applications, where every library we
insist on is a version they may already be fighting over.

## Use

```kotlin
// Once, in Application.onCreate. The key is the public widget key from project settings.
Buggie.start(this, key = "pk_live_…")

// Self-hosting:
Buggie.start(this, key = "pk_live_…", endpoint = "https://bugs.acme.com")
```

Then present your own "report a bug" UI and send it:

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

There is no built-in report sheet. What a bug report looks like inside your app is your
decision, and a sheet we shipped would be the first thing you had to fight.

`Buggie.report(…)` is the same call, blocking, for code that already has a thread to run
it on:

```kotlin
val response = withContext(Dispatchers.IO) { Buggie.report(title = "…") }
```

### Context

The more of this you feed it, the less a report is a guess.

```kotlin
Buggie.identify(id = user.id, email = user.email, name = user.name)
Buggie.setRelease("2.4.1")

Buggie.leaveBreadcrumb("Tapped Pay now")
Buggie.leaveBreadcrumb("Card declined", Breadcrumb.Level.ERROR)

Buggie.record(method = "POST", url = url, status = 500, durationMillis = 410)

try { pay() } catch (error: Exception) { Buggie.record(error) }
```

Breadcrumbs and requests are kept in fixed-size buffers — the last 50 and 30 — so a long
session cannot grow without bound.

### Refusals

A refusal arrives as something you can act on rather than a status code, because "this
account is out of reports" and "slow down" call for different behaviour and neither is
the reporter's fault:

```kotlin
when (val error = result.exceptionOrNull()) {
    is BuggieException.QuotaReached -> show(error.serverMessage) // the server's wording
    is BuggieException.RateLimited -> retryIn(error.retryAfter)
    else -> show("Could not send the report.")
}
```

## What is never sent

- **Request headers and bodies.** Only method, URL, status and duration.
- **Credentials in URLs.** Query parameters named like secrets (`token`, `api_key`,
  `password`, `code`, and others) have their values replaced, and URL fragments are
  dropped entirely, because OAuth flows park tokens there.
- **Anything you did not hand over.** Identity is what you passed to `identify`. Nothing
  is scraped.

### Screenshots

Mark anything private and it is hidden **before** the screen is rendered, not painted
over afterwards:

```kotlin
cardNumberField.buggieRedacted = true
```

or, since most Android interfaces are declared in XML:

```xml
<EditText android:tag="buggie-redact" … />
```

Fields whose `inputType` carries a password variation are redacted without you having to
remember — text, visible, web and numeric PIN alike.

Views are hidden before rendering for the same reason the web widget restyles the DOM
instead of drawing on the finished canvas: a mask positioned by separate arithmetic can
be silently misaligned, and a redaction that can be silently misaligned is not a
redaction. This was a real bug in the web widget before it was a rule here.

Android offers two ways to take the picture and they differ in exactly this respect:

- `PixelCopy` reads the buffer the compositor has **already produced**. Hiding a view
  requests a new frame; it does not wait for one. The copy can come back with the
  password still in it and nothing anywhere would say so. This is the same trap as
  iOS's `drawHierarchy(afterScreenUpdates: false)`, which shipped in the Swift SDK's
  first draft.
- `View.draw(Canvas)` walks the hierarchy synchronously, drawing the state the views are
  in now, and skips anything that is not `VISIBLE` as it goes. The hiding and the
  drawing happen in the same call stack with no frame in between.

So the capture uses `View.draw`. The cost is that content the view system does not draw
itself — `SurfaceView`, `TextureView`, video, dialogs, the system bars — is absent. A
screenshot missing content is a poor screenshot; a screenshot containing a password is
an incident.

## Layout

| Module | What it is |
| --- | --- |
| `buggie-core` | The payload, redaction, buffers, transport. No Android imports. Unit tested. |
| `buggie-android` | Screen capture and device details. Needs a device. |

The split is so that the part deciding what leaves a phone can be tested on any machine,
rather than only where an Android SDK exists. With no Android SDK present, the root
project does not even configure `buggie-android`, and says so.

## Tests

```sh
./test.sh
```

80 tests: redaction, the wire format (including that breadcrumbs go out under the key
the server calls them, `console`), buffer bounds under concurrent writers, the mapping
of refusals, and the screenshot redaction against actual pixels.

The last of those runs under Robolectric in native graphics mode, which rasterises
through the real Android graphics code on the JVM. Every redaction test would pass
against a blank image, so one of them — "the same view does appear when it is not
marked" — exists purely to prove something was drawn at all. On iOS that control was the
only test that caught a completely broken screenshot; the negative assertion beside it
passed happily, because a blank image contains no secret either.

Without an Android SDK, `./test.sh` runs `buggie-core` and says loudly what it did not
check.
