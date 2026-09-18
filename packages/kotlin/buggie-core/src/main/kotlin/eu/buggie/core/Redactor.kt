package eu.buggie.core

import java.net.URI
import java.net.URISyntaxException

/**
 * Keeps out of a report the things that should never leave a device.
 *
 * The same rules as the web widget and the iOS SDK: no credentials in URLs, nothing
 * unbounded, and only what the host application chose to hand over.
 */
public object Redactor {
    public const val UNPARSEABLE: String = "[unparseable url]"

    /**
     * Query parameters that look like credentials.
     *
     * Deliberately identical to `Redactor.swift` and `resources/widget/redact.ts`, and
     * tested against the same twelve names on all three. A name stripped on one
     * platform and not another is a leak that shows up in only some reports.
     */
    private val SECRET_PARAMETERS = setOf(
        "token", "key", "secret", "password", "passwd", "auth", "session",
        "sig", "signature", "access_token", "api_key", "code",
    )

    /**
     * Replace credential-shaped query values, and drop the fragment entirely — OAuth
     * flows routinely park tokens there and it is never useful to us.
     *
     * The rewrite is done on the string rather than by reassembling `URI`'s parts:
     * round-tripping through the parser re-encodes paths and query values, and a URL
     * that comes back subtly different from the one the app requested is a worse
     * report. `URI` is still used to decide whether the input is a URL at all.
     */
    public fun safeUrl(raw: String): String {
        try {
            URI(raw)
        } catch (_: URISyntaxException) {
            return UNPARSEABLE
        }

        val withoutFragment = raw.substringBefore('#')
        val separator = withoutFragment.indexOf('?')

        if (separator < 0) return withoutFragment

        val query = withoutFragment.substring(separator + 1)
            .split('&')
            .joinToString("&") { pair ->
                val name = pair.substringBefore('=')

                if (name.lowercase() in SECRET_PARAMETERS) "$name=[redacted]" else pair
            }

        return withoutFragment.substring(0, separator) + "?" + query
    }

    /** Bound a string so one enormous log line cannot fill the payload. */
    public fun truncate(value: String, limit: Int): String =
        if (value.length > limit) value.take(limit) + "…" else value

    /** Only the identity fields the host app chose to give us, never a scrape. */
    public fun identity(raw: Map<String, String>): Map<String, String> =
        raw.filterValues { it.isNotEmpty() }
}
