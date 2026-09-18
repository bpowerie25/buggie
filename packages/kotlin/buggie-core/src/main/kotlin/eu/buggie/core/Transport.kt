package eu.buggie.core

import java.io.ByteArrayOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

/**
 * Why a report did not arrive, in terms a host app can act on.
 *
 * Distinct types rather than a status code: "this account is out of reports" and "slow
 * down" call for different behaviour, and neither is the reporter's fault.
 */
public sealed class BuggieException(message: String) : RuntimeException(message) {
    /** `start()` was never called, or was called with an empty key. */
    public data class MissingKey(
        val detail: String = "No widget key. Call Buggie.start(key) first.",
    ) : BuggieException(detail)

    /** 402. The server's wording is kept: the widget shows it to the reporter. */
    public data class QuotaReached(val serverMessage: String) : BuggieException(serverMessage)

    /** 429, carrying `Retry-After` in seconds when the server sent one. */
    public data class RateLimited(val retryAfter: Int?) : BuggieException(
        "Too many reports." + (retryAfter?.let { " Try again in $it seconds." } ?: ""),
    )

    /** Anything else the server refused: an unknown key, a disallowed origin. */
    public data class Rejected(val status: Int, val serverMessage: String?) : BuggieException(
        serverMessage ?: "The server refused the report ($status).",
    )

    /** It never got as far as an answer. */
    public data class TransportFailure(val reason: String) : BuggieException(reason)
}

/** What the ingest endpoint says back. */
public data class IngestResponse(
    val id: Long,
    val reference: String,
    val uploadUrl: String?,
) {
    internal companion object {
        fun decode(body: String): IngestResponse {
            val json = Json.parseOrNull(body) as? Map<*, *>
                ?: throw BuggieException.TransportFailure("The server's answer was not JSON.")

            val id = json["id"] as? Long
                ?: throw BuggieException.TransportFailure("The server's answer had no report id.")

            return IngestResponse(
                id = id,
                reference = json["reference"] as? String ?: "R-$id",
                uploadUrl = json["upload_url"] as? String,
            )
        }
    }
}

public data class HttpResponse(
    val status: Int,
    val body: String,
    val headers: Map<String, List<String>> = emptyMap(),
) {
    /** HTTP header names are case-insensitive and servers disagree about casing. */
    public fun header(name: String): String? = headers.entries
        .firstOrNull { it.key.equals(name, ignoreCase = true) }
        ?.value
        ?.firstOrNull()
}

/**
 * The one seam between this SDK and the network.
 *
 * It exists so `send()` itself is tested — the mapping of a refusal is worth pinning
 * down, but so is everything around it: the URL that gets built, the body that gets
 * posted, and the fact that a 402 never reaches the caller as a success.
 */
public fun interface HttpClient {
    public fun post(url: String, contentType: String, body: ByteArray): HttpResponse
}

/**
 * Posts a report, and the screenshot that may follow it.
 *
 * The screenshot goes to its own short-lived signed URL rather than inside the JSON, so
 * a large image never passes through the ingest endpoint — the same arrangement the web
 * widget and the iOS SDK use.
 *
 * Every call here blocks. Android would throw `NetworkOnMainThreadException` long
 * before this mattered, but say it plainly anyway: call it from a background thread, or
 * from `withContext(Dispatchers.IO)`. There is no coroutines dependency, so that choice
 * stays the host app's.
 */
public class Transport(
    endpoint: String,
    private val key: String,
    private val http: HttpClient = UrlConnectionHttpClient(),
) {
    public val endpoint: String = endpoint.trimEnd('/')

    public fun send(report: Report): IngestResponse {
        if (key.isEmpty()) throw BuggieException.MissingKey()

        val response = http.post(
            url = "$endpoint/api/ingest/$key",
            contentType = "application/json",
            body = report.encode().toByteArray(Charsets.UTF_8),
        )

        if (response.status !in 200..299) {
            throw failure(response.status, response.body, response.header("Retry-After"))
        }

        return IngestResponse.decode(response.body)
    }

    /**
     * Upload the screenshot to the signed URL the ingest response handed back.
     *
     * Throws on a refusal, so a host app calling this itself is told. [BuggieClient]
     * swallows it: the report is already filed and a missing image is not worth losing
     * what the reporter wrote.
     */
    public fun upload(screenshot: ByteArray, to: String) {
        val boundary = "buggie.${UUID.randomUUID()}"

        val response = http.post(
            url = to,
            contentType = "multipart/form-data; boundary=$boundary",
            body = multipart(screenshot, boundary),
        )

        if (response.status !in 200..299) {
            throw failure(response.status, response.body, response.header("Retry-After"))
        }
    }

    internal companion object {
        /** Turn a refusal into something the host app can act on, not a bare code. */
        fun failure(status: Int, body: String, retryAfter: String?): BuggieException {
            val message = (Json.parseOrNull(body) as? Map<*, *>)?.get("message") as? String

            return when (status) {
                402 -> BuggieException.QuotaReached(
                    message ?: "This account has reached its monthly report limit.",
                )
                429 -> BuggieException.RateLimited(retryAfter?.trim()?.toIntOrNull())
                else -> BuggieException.Rejected(status, message)
            }
        }

        fun multipart(
            data: ByteArray,
            boundary: String,
            filename: String = "screenshot.jpg",
        ): ByteArray {
            val out = ByteArrayOutputStream()

            out.write(
                (
                    "--$boundary\r\n" +
                        "Content-Disposition: form-data; name=\"screenshot\"; filename=\"$filename\"\r\n" +
                        "Content-Type: image/jpeg\r\n\r\n"
                    ).toByteArray(Charsets.UTF_8),
            )
            out.write(data)
            out.write("\r\n--$boundary--\r\n".toByteArray(Charsets.UTF_8))

            return out.toByteArray()
        }
    }
}

/** The default client: the JDK's own, so the SDK pulls in nothing at all. */
public class UrlConnectionHttpClient(
    private val timeoutMillis: Int = 15_000,
) : HttpClient {
    override fun post(url: String, contentType: String, body: ByteArray): HttpResponse {
        val connection = try {
            URL(url).openConnection() as HttpURLConnection
        } catch (error: Exception) {
            throw BuggieException.TransportFailure(error.message ?: "Could not open $url.")
        }

        try {
            connection.requestMethod = "POST"
            connection.doOutput = true
            connection.connectTimeout = timeoutMillis
            connection.readTimeout = timeoutMillis
            connection.setRequestProperty("Content-Type", contentType)
            connection.setRequestProperty("Accept", "application/json")
            connection.setFixedLengthStreamingMode(body.size)

            // Note: there is no per-connection equivalent of URLSession's
            // httpShouldHandleCookies. If the host app has installed a default
            // CookieHandler it will attach cookies for our own origin — which are
            // cookies it already holds for us, not the user's session with anyone else.
            connection.outputStream.use { it.write(body) }

            val status = connection.responseCode
            val stream = if (status in 200..299) connection.inputStream else connection.errorStream

            return HttpResponse(
                status = status,
                body = stream?.use { it.readBytes().toString(Charsets.UTF_8) } ?: "",
                // The status line comes back under a null key, which no caller wants.
                headers = connection.headerFields.orEmpty()
                    .mapNotNull { (name, values) -> name?.let { it to values } }
                    .toMap(),
            )
        } catch (error: BuggieException) {
            throw error
        } catch (error: Exception) {
            throw BuggieException.TransportFailure(error.message ?: "The request failed.")
        } finally {
            connection.disconnect()
        }
    }
}
