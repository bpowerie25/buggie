package eu.buggie.core

import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import kotlin.test.assertContains
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The payload has to match what the server's `IngestReportRequest` accepts, or native
 * reports fail validation in a way nobody notices until a customer complains.
 */
@DisplayName("Report payload")
class ReportTest {
    /** Round-tripped through the encoder, so the test sees what the server would. */
    private fun encoded(report: Report): Map<*, *> =
        Json.parseOrNull(report.encode()) as? Map<*, *> ?: error("The report did not encode to JSON.")

    private fun sample() = Report(
        title = "Pay now does nothing",
        body = "Nothing happens.",
        reporter = Reporter(name = "Ana", email = "ana@shopper.test", ref = "4821"),
        environment = mapOf("platform" to "android"),
        breadcrumbs = listOf(Breadcrumb(Breadcrumb.Level.ERROR, "boom")),
        network = listOf(NetworkEvent("post", "https://acme.test/pay", 500, 412)),
        error = CapturedError("nil unwrap"),
        screenshot = true,
    )

    @Test
    fun `it encodes the keys the server expects`() {
        val json = encoded(sample())

        assertEquals("Pay now does nothing", json["title"])
        assertEquals(true, json["screenshot"])

        val reporter = json["reporter"] as Map<*, *>
        assertEquals("ana@shopper.test", reporter["email"])
        assertEquals("4821", reporter["ref"])

        val environment = json["environment"] as Map<*, *>
        assertEquals("android", environment["platform"])
    }

    /**
     * The one that would fail silently. `IngestReportRequest` validates `console`; a
     * payload that says `breadcrumbs` loses every log line with no error anywhere.
     */
    @Test
    fun `breadcrumbs are serialised under the key the server calls them`() {
        val json = encoded(sample())

        assertNotNull(json["console"], "Breadcrumbs must be sent as 'console'.")
        assertNull(json["breadcrumbs"], "'breadcrumbs' is not a key the server reads.")

        val first = (json["console"] as List<*>).single() as Map<*, *>
        assertEquals("error", first["level"])
        assertEquals("boom", first["message"])
        assertTrue(first["at"] is Long)
    }

    @Test
    fun `network events are normalised and redacted on the way in`() {
        val event = NetworkEvent(
            method = "post",
            url = "https://acme.test/pay?api_key=leaked",
            status = 500,
            durationMillis = 1500,
        )

        assertEquals("POST", event.method)
        assertEquals(1500, event.durationMillis)
        assertFalse(event.url.contains("leaked"))
    }

    @Test
    fun `a network event serialises the keys the widget sends`() {
        val json = encoded(sample())
        val event = (json["network"] as List<*>).single() as Map<*, *>

        assertEquals("POST", event["method"])
        assertEquals(500L, event["status"])
        assertEquals(412L, event["duration"]) // milliseconds, as the browser sends
        assertEquals("https://acme.test/pay", event["url"])
    }

    @Test
    fun `a breadcrumb cannot be unbounded`() {
        assertEquals(301, Breadcrumb(message = "x".repeat(1000)).message.length)
    }

    @Test
    fun `timestamps are milliseconds since the epoch`() {
        assertEquals(1_700_000_000_500, Breadcrumb(message = "x", at = 1_700_000_000_500).at)
    }

    @Test
    fun `a captured error is truncated but keeps its stack`() {
        val error = CapturedError("m".repeat(3000), stack = "Frame one")

        assertEquals(2001, error.message.length)
        assertEquals("Frame one", error.stack)
    }

    @Test
    fun `an absent body is omitted rather than sent as null`() {
        val json = encoded(Report(title = "Just a title"))

        assertFalse(json.containsKey("body"))
        assertFalse(json.containsKey("error"))
        assertEquals(false, json["screenshot"])
    }

    @Test
    fun `a report stays parseable when a log line carries control characters`() {
        // A stray newline or tab in a log line would otherwise make the entire report
        // unparseable at the far end, losing a report rather than a line.
        val json = encoded(Report(
            title = "Quote \" and backslash \\",
            breadcrumbs = listOf(Breadcrumb(message = "line one\nline\ttwo")),
        ))

        assertEquals("Quote \" and backslash \\", json["title"])
        val first = (json["console"] as List<*>).single() as Map<*, *>
        assertContains(first["message"] as String, "line one\nline\ttwo")
    }
}
