package eu.buggie.core

import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import kotlin.test.assertContains
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * A refusal has to arrive as something the host app can act on. "Over quota" and "slow
 * down" call for different behaviour, and neither is the reporter's fault.
 */
@DisplayName("Transport")
class TransportTest {
    /** Records what was posted and answers with whatever the test asked for. */
    private class FakeHttp(
        private val answer: (String) -> HttpResponse,
    ) : HttpClient {
        val urls = mutableListOf<String>()
        val bodies = mutableListOf<String>()
        val contentTypes = mutableListOf<String>()

        override fun post(url: String, contentType: String, body: ByteArray): HttpResponse {
            urls += url
            contentTypes += contentType
            bodies += body.toString(Charsets.UTF_8)

            return answer(url)
        }
    }

    private fun accepted(uploadUrl: String? = null) = FakeHttp {
        HttpResponse(
            202,
            """{"id":41,"reference":"R-41","upload_url":${uploadUrl?.let { "\"$it\"" } ?: "null"}}""",
        )
    }

    @Test
    fun `a report is posted to the ingest path for its key`() {
        val http = accepted()
        val response = Transport("https://buggie.eu/", "pk_test", http).send(Report(title = "x"))

        assertEquals("https://buggie.eu/api/ingest/pk_test", http.urls.single())
        assertEquals("application/json", http.contentTypes.single())
        assertEquals(41L, response.id)
        assertEquals("R-41", response.reference)
        assertNull(response.uploadUrl)
    }

    @Test
    fun `the body posted is the report`() {
        val http = accepted()
        Transport("https://buggie.eu", "pk_test", http)
            .send(Report(title = "Pay now does nothing", breadcrumbs = listOf(Breadcrumb(message = "tapped"))))

        assertContains(http.bodies.single(), "\"console\"")
        assertContains(http.bodies.single(), "Pay now does nothing")
    }

    @Test
    fun `quota is distinct from every other refusal, and keeps the server's wording`() {
        val failure = Transport.failure(
            402,
            """{"message":"This site has reached its monthly report limit."}""",
            null,
        )

        assertIs<BuggieException.QuotaReached>(failure, "402 should be a quota problem, got $failure")
        assertContains(failure.serverMessage, "monthly report limit")
    }

    @Test
    fun `quota still explains itself when the server sends no message`() {
        val failure = Transport.failure(402, "", null)

        assertIs<BuggieException.QuotaReached>(failure, "402 should be a quota problem, got $failure")
        assertTrue(failure.serverMessage.isNotEmpty())
    }

    @Test
    fun `rate limiting carries Retry-After so the app can back off`() {
        assertEquals(BuggieException.RateLimited(42), Transport.failure(429, "", "42"))
    }

    @Test
    fun `a missing Retry-After is absent, not zero`() {
        assertEquals(BuggieException.RateLimited(null), Transport.failure(429, "", null))
    }

    @Test
    fun `other refusals keep their status and message`() {
        val failure = Transport.failure(403, """{"message":"Origin not allowed."}""", null)

        assertEquals(BuggieException.Rejected(403, "Origin not allowed."), failure)
    }

    @Test
    fun `a refusal reaches the caller rather than being read as a report id`() {
        val http = FakeHttp { HttpResponse(429, "", mapOf("retry-after" to listOf("30"))) }

        val failure = assertFailsWith<BuggieException.RateLimited> {
            Transport("https://buggie.eu", "pk_test", http).send(Report(title = "x"))
        }

        // Lower-cased by the server, as HTTP/2 requires; the lookup must not care.
        assertEquals(30, failure.retryAfter)
    }

    @Test
    fun `an empty key is refused before anything reaches the network`() {
        val http = accepted()

        assertFailsWith<BuggieException.MissingKey> {
            Transport("https://buggie.eu", "", http).send(Report(title = "x"))
        }

        assertTrue(http.urls.isEmpty(), "Nothing should have been sent.")
    }

    @Test
    fun `the ingest response decodes the server's snake_case upload URL`() {
        val response = IngestResponse.decode(
            """{"id":41,"reference":"R-41","upload_url":"https://buggie.eu/u/abc"}""",
        )

        assertEquals("R-41", response.reference)
        assertEquals("https://buggie.eu/u/abc", response.uploadUrl)
    }

    @Test
    fun `an answer that is not a report is a transport failure, not a crash`() {
        assertFailsWith<BuggieException.TransportFailure> { IngestResponse.decode("<html>502</html>") }
    }

    @Test
    fun `the multipart body is well formed`() {
        val body = Transport.multipart(byteArrayOf(0xFF.toByte(), 0xD8.toByte(), 0xFF.toByte()), "abc")
        val text = body.toString(Charsets.ISO_8859_1)

        assertTrue(text.startsWith("--abc\r\n"))
        assertContains(text, "name=\"screenshot\"; filename=\"screenshot.jpg\"")
        assertContains(text, "Content-Type: image/jpeg")
        assertTrue(text.endsWith("\r\n--abc--\r\n"))
    }

    @Test
    fun `the screenshot goes to the signed URL the server handed back`() {
        val http = FakeHttp { url ->
            if (url.contains("ingest")) {
                HttpResponse(202, """{"id":7,"reference":"R-7","upload_url":"https://buggie.eu/u/abc"}""")
            } else {
                HttpResponse(200, """{"ok":true}""")
            }
        }

        val transport = Transport("https://buggie.eu", "pk_test", http)
        val response = transport.send(Report(title = "x", screenshot = true))
        transport.upload(byteArrayOf(1, 2, 3), response.uploadUrl!!)

        assertEquals("https://buggie.eu/u/abc", http.urls[1])
        assertContains(http.contentTypes[1], "multipart/form-data; boundary=buggie.")
    }
}
