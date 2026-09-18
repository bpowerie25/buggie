package eu.buggie.core

/**
 * What the server is told about a bug.
 *
 * Mirrors the widget's payload so one ingest endpoint serves web and native alike: see
 * `docs/DESIGN.md` and `IngestReportRequest` on the server. Identical to the iOS SDK's
 * `Report`, field for field and key for key.
 */
public data class Report(
    val title: String,
    val body: String? = null,
    val reporter: Reporter = Reporter(),
    val environment: Map<String, Any?> = emptyMap(),
    val breadcrumbs: List<Breadcrumb> = emptyList(),
    val network: List<NetworkEvent> = emptyList(),
    val error: CapturedError? = null,
    val screenshot: Boolean = false,
) {
    /**
     * The payload, as the server reads it.
     *
     * Absent values are omitted rather than sent as null, matching the browser: the
     * server's rules are `nullable`, but an absent key and an explicit null are not the
     * same thing to every rule Laravel has.
     */
    public fun toJson(): Map<String, Any?> = buildMap {
        put("title", title)
        body?.let { put("body", it) }
        put("reporter", reporter.toJson())
        put("environment", environment)

        // The server calls these "console". On a device they are breadcrumbs, but the
        // wire format is shared with the web widget and the server's name wins. A
        // silent rename on either side fails validation with no visible symptom.
        put("console", breadcrumbs.map { it.toJson() })

        put("network", network.map { it.toJson() })
        error?.let { put("error", it.toJson()) }
        put("screenshot", screenshot)
    }

    internal fun encode(): String = Json.write(toJson())
}

public data class Reporter(
    val name: String? = null,
    val email: String? = null,
    val ref: String? = null,
) {
    internal fun toJson(): Map<String, Any?> = buildMap {
        name?.let { put("name", it) }
        email?.let { put("email", it) }
        ref?.let { put("ref", it) }
    }
}

/** A log line, tagged with when and how bad. */
public class Breadcrumb(
    level: Level = Level.INFO,
    message: String,
    at: Long = System.currentTimeMillis(),
) {
    public enum class Level(internal val wire: String) {
        DEBUG("debug"), INFO("info"), WARN("warn"), ERROR("error")
    }

    public val level: Level = level

    public val message: String = Redactor.truncate(message, 300)

    /** Milliseconds since the epoch, matching what the browser sends. */
    public val at: Long = at

    internal fun toJson(): Map<String, Any?> = mapOf(
        "level" to level.wire,
        "message" to message,
        "at" to at,
    )
}

/** Method, URL, status and duration. Never headers, never bodies. */
public class NetworkEvent(
    method: String,
    url: String,
    public val status: Int,
    public val durationMillis: Long,
    public val at: Long = System.currentTimeMillis(),
) {
    public val method: String = method.uppercase()

    public val url: String = Redactor.safeUrl(url)

    internal fun toJson(): Map<String, Any?> = mapOf(
        "method" to method,
        "url" to url,
        "status" to status,
        "duration" to durationMillis,
        "at" to at,
    )
}

public class CapturedError(message: String, stack: String? = null) {
    public val message: String = Redactor.truncate(message, 2000)

    public val stack: String? = stack?.let { Redactor.truncate(it, 8000) }

    internal fun toJson(): Map<String, Any?> = buildMap {
        put("message", message)
        stack?.let { put("stack", it) }
    }
}
