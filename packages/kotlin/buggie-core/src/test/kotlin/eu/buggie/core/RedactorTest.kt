package eu.buggie.core

import org.junit.jupiter.api.Test
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.params.ParameterizedTest
import org.junit.jupiter.params.provider.ValueSource
import kotlin.test.assertContains
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/**
 * The rules that keep things off the wire. The same intent as the web widget's
 * redaction tests and the iOS SDK's, because the same promise is being made to the
 * same people.
 */
@DisplayName("Redaction")
class RedactorTest {
    @Test
    fun `credential-shaped parameters are replaced, everything else is kept`() {
        val safe = Redactor.safeUrl("https://acme.test/reset?token=supersecret&page=2")

        assertFalse(safe.contains("supersecret"))
        assertContains(safe, "redacted")
        // The rest survives; a redacted report still has to be a useful one.
        assertContains(safe, "page=2")
    }

    /**
     * The list is deliberately identical to `Redactor.swift` and `redact.ts`, and all
     * three are tested against these same twelve names. A name stripped on one platform
     * and not another is a leak visible in only some reports.
     */
    @ParameterizedTest(name = "{0} is redacted")
    @ValueSource(
        strings = [
            "token", "KEY", "Secret", "password", "passwd", "auth", "session",
            "sig", "signature", "access_token", "api_key", "code",
        ],
    )
    fun `every credential name is caught regardless of casing`(name: String) {
        val safe = Redactor.safeUrl("https://acme.test/x?$name=leaked")

        assertFalse(safe.contains("leaked"), "$name was not redacted: $safe")
        assertContains(safe, "$name=[redacted]")
    }

    @Test
    fun `a parameter that merely resembles a credential name is left alone`() {
        // The control for the test above. Matching is on the whole name, not a
        // substring, or a report loses the very parameters that explain the bug.
        val safe = Redactor.safeUrl("https://acme.test/x?token_type=bearer&keyword=shoes&decode=1")

        assertContains(safe, "token_type=bearer")
        assertContains(safe, "keyword=shoes")
        assertContains(safe, "decode=1")
    }

    @Test
    fun `fragments are dropped entirely`() {
        // OAuth flows park tokens in the fragment and it is never useful to us.
        val safe = Redactor.safeUrl("https://acme.test/callback#access_token=leaked&state=x")

        assertFalse(safe.contains("leaked"))
        assertFalse(safe.contains("#"))
    }

    @Test
    fun `a URL with no query survives untouched`() {
        assertEquals("https://acme.test/basket", Redactor.safeUrl("https://acme.test/basket"))
    }

    @Test
    fun `unparseable input does not leak itself`() {
        assertEquals(Redactor.UNPARSEABLE, Redactor.safeUrl("http://  /?token=leaked"))
    }

    @Test
    fun `truncation is bounded and leaves short values alone`() {
        assertEquals(101, Redactor.truncate("x".repeat(5000), 100).length)
        assertEquals("short", Redactor.truncate("short", 100))
    }

    @Test
    fun `identity keeps only what was actually given`() {
        val identity = Redactor.identity(mapOf("email" to "a@b.test", "name" to "", "id" to "7"))

        assertEquals(mapOf("email" to "a@b.test", "id" to "7"), identity)
    }

    @Test
    fun `a value that happens to contain an ampersand does not smuggle a parameter through`() {
        val safe = Redactor.safeUrl("https://acme.test/x?q=a%26token%3Dleaked&token=real")

        assertTrue(safe.contains("token=[redacted]"), safe)
        // The encoded one is not a parameter at all and must not be mangled.
        assertContains(safe, "q=a%26token%3Dleaked")
    }
}
