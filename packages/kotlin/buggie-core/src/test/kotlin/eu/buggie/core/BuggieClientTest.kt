package eu.buggie.core

import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.Date
import kotlin.test.assertContains
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The assembly around the device details.
 *
 * On iOS this was where the bug lived — device details were read from the wrong thread
 * because they were gathered inside the payload builder. Here they arrive through
 * [EnvironmentSource], which makes the whole of this testable with no device at all.
 */
@DisplayName("Client")
class BuggieClientTest {
    private fun client(device: EnvironmentSource = EnvironmentSource { emptyMap() }) =
        BuggieClient(device)

    @Test
    fun `it always says what it is and when`() {
        val environment = client().environment(null, emptyMap())

        assertEquals("android", environment["platform"])
        assertTrue((environment["captured_at"] as String).endsWith("Z"))
    }

    @Test
    fun `absent release and identity are omitted, not sent empty`() {
        val environment = client().environment(null, emptyMap())

        assertFalse(environment.containsKey("release"))
        assertFalse(environment.containsKey("identity"))
    }

    @Test
    fun `release and identity ride along when given`() {
        val environment = client().environment("2.4.1", mapOf("id" to "7"))

        assertEquals("2.4.1", environment["release"])
        assertEquals(mapOf("id" to "7"), environment["identity"])
    }

    @Test
    fun `the timestamp is UTC, whatever the phone thinks the time is`() {
        assertEquals("2023-11-14T22:13:20Z", timestamp(Date(1_700_000_000_000)))
    }

    @Test
    fun `the device's own details are merged in`() {
        val client = client(EnvironmentSource { mapOf("device" to "Pixel 8", "os" to "Android 15") })

        val report = client.buildReport("Pay now does nothing")

        assertEquals("Pixel 8", report.environment["device"])
        assertEquals("android", report.environment["platform"])
    }

    @Test
    fun `identity fills the reporter, and an explicit email wins`() {
        val client = client()
        client.identify(id = "4821", email = "ana@shopper.test", name = "Ana")

        assertEquals("Ana", client.buildReport("x").reporter.name)
        assertEquals("4821", client.buildReport("x").reporter.ref)
        assertEquals("ana@shopper.test", client.buildReport("x").reporter.email)
        assertEquals("other@shopper.test", client.buildReport("x", reporterEmail = "other@shopper.test").reporter.email)
    }

    @Test
    fun `an empty identity field is not recorded at all`() {
        val client = client()
        client.identify(id = "4821", name = "")

        assertNull(client.buildReport("x").reporter.name)
    }

    @Test
    fun `context accumulates into the report and stays bounded`() {
        val client = client()
        repeat(80) { client.leaveBreadcrumb("step $it") }
        repeat(50) { client.record("GET", "https://acme.test/$it", 200, 12) }

        val report = client.buildReport("x")

        assertEquals(BuggieClient.BREADCRUMB_LIMIT, report.breadcrumbs.size)
        assertEquals(BuggieClient.NETWORK_LIMIT, report.network.size)
        assertEquals("step 79", report.breadcrumbs.last().message)
    }

    @Test
    fun `a recorded error rides along, and its URL is redacted like any other`() {
        val client = client()
        client.record(IllegalStateException("card declined"))
        client.record("POST", "https://acme.test/pay?token=leaked", 500, 40)

        val report = client.buildReport("x")

        assertContains(report.error!!.message, "card declined")
        assertFalse(report.network.single().url.contains("leaked"))
    }

    @Test
    fun `reporting before start is refused rather than silently dropped`() {
        assertFailsWith<BuggieException.MissingKey> { client().report("x") }
    }

    @Test
    fun `a screenshot is announced only when there is one`() {
        val client = client()

        assertFalse(client.buildReport("x").screenshot)
        assertTrue(client.buildReport("x", screenshot = true).screenshot)
    }

    @Test
    fun `a failed screenshot upload does not fail an otherwise good report`() {
        // The person who hit the bug has already written it up. Losing the whole
        // report because the image would not upload is the wrong trade.
        val http = HttpClient { url, _, _ ->
            if (url.contains("ingest")) {
                HttpResponse(202, """{"id":7,"reference":"R-7","upload_url":"https://buggie.eu/u/abc"}""")
            } else {
                HttpResponse(500, """{"message":"Storage is down."}""")
            }
        }

        val client = client()
        client.start("pk_test", "https://buggie.eu", http)

        assertEquals(7L, client.report("x", screenshot = byteArrayOf(1, 2, 3)).id)
    }
}
