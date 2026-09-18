package eu.buggie.core

import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Anything extra the platform can say about itself.
 *
 * The Android module supplies one of these. It is an interface so that everything
 * around it — the buffers, the identity, the assembly of the payload — is testable on
 * any machine, which is the whole reason this module exists.
 */
public fun interface EnvironmentSource {
    public fun environment(): Map<String, Any?>
}

/**
 * The client, less the parts that need a device.
 *
 * Holds the context an app accumulates and turns it into a report. There is
 * deliberately no report sheet anywhere in this SDK: what a bug report looks like
 * inside someone's app is their decision, and a sheet we shipped would be the first
 * thing they had to fight.
 */
public class BuggieClient(
    private val device: EnvironmentSource = EnvironmentSource { emptyMap() },
) {
    public companion object {
        public const val DEFAULT_ENDPOINT: String = "https://buggie.eu"

        /** The last 50 and the last 30, as the server caps them and the widget sends them. */
        internal const val BREADCRUMB_LIMIT = 50
        internal const val NETWORK_LIMIT = 30
    }

    private val breadcrumbs = RingBuffer<Breadcrumb>(BREADCRUMB_LIMIT)
    private val network = RingBuffer<NetworkEvent>(NETWORK_LIMIT)
    private val lock = Any()

    private var transport: Transport? = null
    private var identity: MutableMap<String, String> = mutableMapOf()
    private var release: String? = null
    private var lastError: CapturedError? = null

    // Setting up

    /**
     * Point the client at an instance.
     *
     * @param key the public widget key from project settings.
     * @param endpoint where Buggie lives. Defaults to the hosted service.
     * @param http replaceable so a host app can post through its own stack.
     */
    @JvmOverloads
    public fun start(
        key: String,
        endpoint: String = DEFAULT_ENDPOINT,
        http: HttpClient = UrlConnectionHttpClient(),
    ) {
        synchronized(lock) {
            transport = Transport(endpoint, key, http)
        }
    }

    @JvmOverloads
    public fun identify(
        id: String? = null,
        email: String? = null,
        name: String? = null,
        extra: Map<String, String> = emptyMap(),
    ) {
        val merged = buildMap {
            putAll(extra)
            id?.let { put("id", it) }
            email?.let { put("email", it) }
            name?.let { put("name", it) }
        }

        synchronized(lock) {
            identity.putAll(Redactor.identity(merged))
        }
    }

    public fun setRelease(value: String) {
        synchronized(lock) { release = value }
    }

    // Context

    /** Record something that might explain a later bug. */
    @JvmOverloads
    public fun leaveBreadcrumb(message: String, level: Breadcrumb.Level = Breadcrumb.Level.INFO) {
        breadcrumbs.append(Breadcrumb(level, message))
    }

    /**
     * Record a request. Method, URL, status and duration only — never headers or
     * bodies, the same as the web widget.
     *
     * Milliseconds rather than the iOS SDK's seconds: everything that measures elapsed
     * time on this platform already is, and a unit conversion at the call site is a
     * factor of a thousand waiting to happen.
     */
    public fun record(method: String, url: String, status: Int, durationMillis: Long) {
        network.append(NetworkEvent(method, url, status, durationMillis))
    }

    /**
     * Attach an error so the next report carries it, and so the server can group
     * repeat occurrences by fingerprint.
     */
    @JvmOverloads
    public fun record(error: Throwable, stack: String? = null) {
        synchronized(lock) {
            lastError = CapturedError(
                message = "${error::class.java.name}: ${error.message ?: ""}".trimEnd(':', ' '),
                stack = stack ?: error.stackTrace.joinToString("\n") { it.toString() },
            )
        }
    }

    public fun clearContext() {
        breadcrumbs.clear()
        network.clear()
        synchronized(lock) { lastError = null }
    }

    // Reporting

    /**
     * Build the report this moment would produce.
     *
     * Split out from [report] so a host app can see exactly what would be sent, and so
     * the assembly is testable without a network.
     */
    @JvmOverloads
    public fun buildReport(
        title: String,
        body: String? = null,
        reporterEmail: String? = null,
        screenshot: Boolean = false,
    ): Report {
        val state = snapshot()

        return Report(
            title = title,
            body = body,
            reporter = Reporter(
                name = state.identity["name"],
                email = reporterEmail ?: state.identity["email"],
                ref = state.identity["id"],
            ),
            environment = environment(state.release, state.identity) + device.environment(),
            breadcrumbs = breadcrumbs.entries,
            network = network.entries,
            error = state.error,
            screenshot = screenshot,
        )
    }

    /**
     * Send a report, blocking until the server answers. `screenshot` is JPEG bytes the
     * host app supplies — typically from `Buggie.captureScreen()`, but anything will do.
     */
    @JvmOverloads
    public fun report(
        title: String,
        body: String? = null,
        reporterEmail: String? = null,
        screenshot: ByteArray? = null,
    ): IngestResponse {
        val transport = snapshot().transport ?: throw BuggieException.MissingKey()

        val response = transport.send(
            buildReport(title, body, reporterEmail, screenshot = screenshot != null),
        )

        if (screenshot != null && response.uploadUrl != null) {
            // A failed image must not fail an otherwise good report.
            try {
                transport.upload(screenshot, response.uploadUrl)
            } catch (_: BuggieException) {
                // Deliberately swallowed. The report is already filed.
            }
        }

        return response
    }

    /**
     * What can be said about a report without asking the device.
     *
     * The device's own details arrive through [EnvironmentSource] instead, because on
     * iOS reading them off the main actor was a real bug and the same shape of mistake
     * is available here.
     */
    internal fun environment(release: String?, identity: Map<String, String>): Map<String, Any?> =
        buildMap {
            put("platform", "android")
            put("captured_at", timestamp())
            release?.let { put("release", it) }
            if (identity.isNotEmpty()) put("identity", identity)
        }

    private fun snapshot(): Snapshot = synchronized(lock) {
        Snapshot(transport, identity.toMap(), release, lastError)
    }

    private class Snapshot(
        val transport: Transport?,
        val identity: Map<String, String>,
        val release: String?,
        val error: CapturedError?,
    )
}

/**
 * ISO 8601, in UTC.
 *
 * `SimpleDateFormat` rather than `java.time`: this module is compiled into Android apps
 * that may still support API levels below 26, where `java.time` is absent without
 * desugaring the host app has not agreed to. A fresh instance each time, because
 * `SimpleDateFormat` is not thread-safe and a report can be filed from anywhere.
 */
internal fun timestamp(at: Date = Date()): String =
    SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss'Z'", Locale.US)
        .apply { timeZone = TimeZone.getTimeZone("UTC") }
        .format(at)
