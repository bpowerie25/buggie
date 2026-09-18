package eu.buggie.core

import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull

/**
 * The encoder is ours, so it is tested rather than trusted. Everything a report can
 * carry comes from a customer's application, including the parts that will try to
 * break it.
 */
@DisplayName("JSON")
class JsonTest {
    @Test
    fun `it writes what the server can read back`() {
        val written = Json.write(
            mapOf(
                "title" to "Pay now",
                "screenshot" to true,
                "console" to listOf(mapOf("at" to 1_700_000_000_500L)),
                "missing" to null,
            ),
        )

        assertEquals(
            """{"title":"Pay now","screenshot":true,"console":[{"at":1700000000500}],"missing":null}""",
            written,
        )
    }

    @Test
    fun `quotes, backslashes and control characters are escaped`() {
        val round = Json.parseOrNull(Json.write(mapOf("m" to "a\"b\\c\nde"))) as Map<*, *>

        assertEquals("a\"b\\c\nde", round["m"])
    }

    @Test
    fun `an integer stays an integer`() {
        // A report id that comes back as 41.0 builds the wrong URL the moment it is
        // interpolated into one.
        assertEquals(41L, (Json.parseOrNull("""{"id":41}""") as Map<*, *>)["id"])
        assertEquals(0.5, (Json.parseOrNull("""{"ratio":0.5}""") as Map<*, *>)["ratio"])
    }

    @Test
    fun `an infinity is not written as one`() {
        // "Infinity" is not JSON, and one bad number would fail the whole report.
        assertEquals("""{"d":null}""", Json.write(mapOf("d" to Double.POSITIVE_INFINITY)))
        assertEquals("""{"d":null}""", Json.write(mapOf("d" to Double.NaN)))
    }

    @Test
    fun `rubbish parses to null rather than throwing`() {
        assertNull(Json.parseOrNull("<html>502 Bad Gateway</html>"))
        assertNull(Json.parseOrNull(""))
        assertNull(Json.parseOrNull("""{"unterminated": "x"""))
        assertNull(Json.parseOrNull("""{"trailing":1} junk"""))
    }

    @Test
    fun `nesting and empties round-trip`() {
        val text = """{"a":[],"b":{},"c":[1,{"d":"e"}],"f":true,"g":null}"""

        assertEquals(text, Json.write(Json.parseOrNull(text)))
    }
}
