package eu.buggie.core

/**
 * Just enough JSON to build a report and read the server's answer back.
 *
 * Hand-rolled rather than depended upon, for the same reason the web widget carries no
 * runtime dependencies: this code is compiled into other people's applications, where
 * every library we insist on is a version they may already be fighting over. The
 * payload is small and fixed, so the cost of owning it is small and fixed too.
 */
internal object Json {
    /** Serialise a tree of maps, lists, strings, numbers and booleans. */
    fun write(value: Any?): String = StringBuilder().also { writeValue(value, it) }.toString()

    /** Parse, or null. Everything reading this is reading a server having a bad day. */
    fun parseOrNull(text: String): Any? = try {
        Parser(text).parse()
    } catch (_: IllegalArgumentException) {
        null
    }

    private fun writeValue(value: Any?, out: StringBuilder) {
        when (value) {
            null -> out.append("null")
            is String -> writeString(value, out)
            is Boolean -> out.append(value)
            is Int, is Long -> out.append(value)
            is Number -> {
                val number = value.toDouble()

                // JSON has no infinity and no NaN. Omitting the value loses a field;
                // writing "Infinity" fails validation for the whole report.
                if (number.isFinite()) out.append(number) else out.append("null")
            }
            is Map<*, *> -> {
                out.append('{')
                var first = true

                for ((key, entry) in value) {
                    if (!first) out.append(',')
                    first = false
                    writeString(key.toString(), out)
                    out.append(':')
                    writeValue(entry, out)
                }

                out.append('}')
            }
            is Iterable<*> -> {
                out.append('[')
                var first = true

                for (element in value) {
                    if (!first) out.append(',')
                    first = false
                    writeValue(element, out)
                }

                out.append(']')
            }
            else -> writeString(value.toString(), out)
        }
    }

    private fun writeString(value: String, out: StringBuilder) {
        out.append('"')

        for (character in value) {
            when {
                character == '"' -> out.append("\\\"")
                character == '\\' -> out.append("\\\\")
                character == '\n' -> out.append("\\n")
                character == '\r' -> out.append("\\r")
                character == '\t' -> out.append("\\t")
                // Everything else below a space has no escape of its own and is
                // illegal raw. One stray control character in a log line would
                // otherwise make the whole report unparseable at the far end.
                character < ' ' -> out.append("\\u%04x".format(character.code))
                else -> out.append(character)
            }
        }

        out.append('"')
    }

    private class Parser(private val text: String) {
        private var at = 0

        fun parse(): Any? {
            val value = value()
            skipWhitespace()
            require(at >= text.length) { "Trailing content at $at." }

            return value
        }

        private fun value(): Any? {
            skipWhitespace()
            require(at < text.length) { "Unexpected end of input." }

            return when (text[at]) {
                '{' -> obj()
                '[' -> array()
                '"' -> string()
                't' -> literal("true", true)
                'f' -> literal("false", false)
                'n' -> literal("null", null)
                else -> number()
            }
        }

        private fun obj(): Map<String, Any?> {
            val result = LinkedHashMap<String, Any?>()
            at++
            skipWhitespace()

            if (peek() == '}') {
                at++

                return result
            }

            while (true) {
                skipWhitespace()
                val key = string()
                skipWhitespace()
                expect(':')
                result[key] = value()
                skipWhitespace()

                if (expectOneOf(',', '}') == '}') return result
            }
        }

        private fun array(): List<Any?> {
            val result = mutableListOf<Any?>()
            at++
            skipWhitespace()

            if (peek() == ']') {
                at++

                return result
            }

            while (true) {
                result += value()
                skipWhitespace()

                if (expectOneOf(',', ']') == ']') return result
            }
        }

        private fun string(): String {
            expect('"')
            val out = StringBuilder()

            while (true) {
                require(at < text.length) { "Unterminated string." }

                when (val character = text[at++]) {
                    '"' -> return out.toString()
                    '\\' -> out.append(escape())
                    else -> out.append(character)
                }
            }
        }

        private fun escape(): Char {
            require(at < text.length) { "Unterminated escape." }

            return when (val marker = text[at++]) {
                '"', '\\', '/' -> marker
                'b' -> ''
                'f' -> ''
                'n' -> '\n'
                'r' -> '\r'
                't' -> '\t'
                'u' -> {
                    require(at + 4 <= text.length) { "Truncated unicode escape." }
                    val code = text.substring(at, at + 4).toInt(16).toChar()
                    at += 4

                    code
                }
                else -> throw IllegalArgumentException("Unknown escape at $at.")
            }
        }

        private fun number(): Any {
            val start = at
            while (at < text.length && (text[at].isDigit() || text[at] in "-+.eE")) at++
            val raw = text.substring(start, at)

            // Integers stay integers: a report id that comes back as 41.0 is an id
            // that builds the wrong URL the moment it is interpolated into one.
            return raw.toLongOrNull()
                ?: raw.toDoubleOrNull()
                ?: throw IllegalArgumentException("Bad number '$raw' at $start.")
        }

        private fun literal(word: String, value: Any?): Any? {
            require(text.startsWith(word, at)) { "Expected $word at $at." }
            at += word.length

            return value
        }

        private fun peek(): Char? = text.getOrNull(at)

        private fun expect(character: Char) {
            require(peek() == character) { "Expected '$character' at $at." }
            at++
        }

        private fun expectOneOf(vararg characters: Char): Char {
            val found = peek()
            require(found != null && found in characters) { "Expected one of ${characters.toList()} at $at." }
            at++

            return found
        }

        fun skipWhitespace() {
            while (at < text.length && text[at].isWhitespace()) at++
        }
    }
}
