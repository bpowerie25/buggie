package eu.buggie.android

import android.app.Activity
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.view.View
import eu.buggie.core.Breadcrumb
import eu.buggie.core.BuggieClient
import eu.buggie.core.BuggieException
import eu.buggie.core.HttpClient
import eu.buggie.core.IngestResponse
import eu.buggie.core.Report
import eu.buggie.core.UrlConnectionHttpClient
import java.util.concurrent.Executors

/**
 * The Buggie client.
 *
 * Everything that can be reasoned about without a screen lives in `buggie-core` and is
 * unit tested. This object holds the two things that genuinely need a device — the
 * screenshot and what the device knows about itself — and otherwise gets out of the
 * way.
 *
 * There is deliberately no report sheet here. What a bug report should look like inside
 * an app is that app's decision, and a sheet we shipped would be the first thing
 * anybody had to fight. Present your own UI and call [report].
 */
public object Buggie {
    private val sender = Executors.newSingleThreadExecutor { runnable ->
        Thread(runnable, "buggie-report").apply { isDaemon = true }
    }

    private var client: BuggieClient = BuggieClient()

    // Setting up

    /**
     * Point the client at an instance. Call once, from `Application.onCreate`.
     *
     * @param key the public widget key from project settings.
     * @param endpoint where Buggie lives. Defaults to the hosted service.
     */
    @JvmStatic
    @JvmOverloads
    public fun start(
        context: Context,
        key: String,
        endpoint: String = BuggieClient.DEFAULT_ENDPOINT,
        http: HttpClient = UrlConnectionHttpClient(),
    ) {
        val application = context.applicationContext as android.app.Application
        ForegroundActivity.install(application)

        client = BuggieClient(AndroidEnvironment(application) { ForegroundActivity.activity })
        client.start(key, endpoint, http)
    }

    @JvmStatic
    @JvmOverloads
    public fun identify(
        id: String? = null,
        email: String? = null,
        name: String? = null,
        extra: Map<String, String> = emptyMap(),
    ): Unit = client.identify(id, email, name, extra)

    @JvmStatic
    public fun setRelease(value: String): Unit = client.setRelease(value)

    // Context

    /** Record something that might explain a later bug. */
    @JvmStatic
    @JvmOverloads
    public fun leaveBreadcrumb(
        message: String,
        level: Breadcrumb.Level = Breadcrumb.Level.INFO,
    ): Unit = client.leaveBreadcrumb(message, level)

    /** Method, URL, status and duration only — never headers, never bodies. */
    @JvmStatic
    public fun record(method: String, url: String, status: Int, durationMillis: Long): Unit =
        client.record(method, url, status, durationMillis)

    /** Attach an error so the next report carries it. */
    @JvmStatic
    @JvmOverloads
    public fun record(error: Throwable, stack: String? = null): Unit = client.record(error, stack)

    // Screenshots

    /**
     * A JPEG of the activity in front, with anything marked private left out of it.
     *
     * Must be called from the main thread, and returns null if there is no activity to
     * capture. See [ScreenCapture] for why the picture is taken the way it is.
     */
    @JvmStatic
    @JvmOverloads
    public fun captureScreen(
        activity: Activity? = ForegroundActivity.activity,
        quality: Int = ScreenCapture.DEFAULT_QUALITY,
    ): ByteArray? = activity?.window?.decorView?.let { ScreenCapture.capture(it, quality) }

    /** The same capture, of any view. */
    @JvmStatic
    @JvmOverloads
    public fun capture(view: View, quality: Int = ScreenCapture.DEFAULT_QUALITY): ByteArray? =
        ScreenCapture.capture(view, quality)

    // Reporting

    /** What would be sent right now, for a host app that wants to see it first. */
    @JvmStatic
    @JvmOverloads
    public fun buildReport(
        title: String,
        body: String? = null,
        reporterEmail: String? = null,
        screenshot: Boolean = false,
    ): Report = client.buildReport(title, body, reporterEmail, screenshot)

    /**
     * Send a report, blocking until the server answers.
     *
     * Call it from a background thread, or from `withContext(Dispatchers.IO)`. There is
     * no coroutines dependency here, so that choice stays the host app's — see
     * [reportInBackground] if you would rather not make it.
     */
    @JvmStatic
    @JvmOverloads
    @Throws(BuggieException::class)
    public fun report(
        title: String,
        body: String? = null,
        reporterEmail: String? = null,
        screenshot: ByteArray? = null,
    ): IngestResponse = client.report(title, body, reporterEmail, screenshot)

    /**
     * The same, off the main thread, answering on it.
     *
     * The screenshot has to be captured on the main thread and the send must not happen
     * there. That is a trap worth one small helper rather than a paragraph in a README
     * nobody reads until afterwards.
     */
    @JvmStatic
    @JvmOverloads
    public fun reportInBackground(
        title: String,
        body: String? = null,
        reporterEmail: String? = null,
        screenshot: ByteArray? = null,
        onResult: (Result<IngestResponse>) -> Unit = {},
    ) {
        val main = Handler(Looper.getMainLooper())

        sender.execute {
            val result = runCatching { client.report(title, body, reporterEmail, screenshot) }

            main.post { onResult(result) }
        }
    }
}
