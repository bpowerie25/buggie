import Foundation
import Testing
@testable import BuggieCore

/// A refusal has to arrive as something the host app can act on. "Over quota" and
/// "slow down" call for different behaviour, and neither is the reporter's fault.
@Suite("Transport")
struct TransportTests {
    private func response(_ status: Int, headers: [String: String] = [:]) throws -> HTTPURLResponse {
        try #require(HTTPURLResponse(
            url: URL(string: "https://buggie.eu/api/ingest/pk_test")!,
            statusCode: status,
            httpVersion: nil,
            headerFields: headers
        ))
    }

    @Test("Quota is distinct from every other refusal, and keeps the server's wording")
    func quota() throws {
        let body = #"{"message":"This site has reached its monthly report limit."}"#.data(using: .utf8)!

        let failure = Transport.failure(status: 402, data: body, headers: try response(402))

        guard case let .quotaReached(message) = failure else {
            Issue.record("402 should be reported as a quota problem, got \(failure)")
            return
        }

        #expect(message.contains("monthly report limit"))
    }

    @Test("Quota still explains itself when the server sends no message")
    func quotaWithoutMessage() throws {
        let failure = Transport.failure(status: 402, data: Data(), headers: try response(402))

        guard case let .quotaReached(message) = failure else {
            Issue.record("402 should be reported as a quota problem, got \(failure)")
            return
        }

        #expect(!message.isEmpty)
    }

    @Test("Rate limiting carries Retry-After so the app can back off")
    func rateLimited() throws {
        let failure = Transport.failure(
            status: 429,
            data: Data(),
            headers: try response(429, headers: ["Retry-After": "42"])
        )

        #expect(failure == .rateLimited(retryAfter: 42))
    }

    @Test("A missing Retry-After is absent, not zero")
    func rateLimitedWithoutHeader() throws {
        let failure = Transport.failure(status: 429, data: Data(), headers: try response(429))

        #expect(failure == .rateLimited(retryAfter: nil))
    }

    @Test("Other refusals keep their status and message")
    func rejection() throws {
        let body = #"{"message":"Origin not allowed."}"#.data(using: .utf8)!

        let failure = Transport.failure(status: 403, data: body, headers: try response(403))

        #expect(failure == .rejected(status: 403, message: "Origin not allowed."))
    }

    @Test("The multipart body is well formed")
    func multipart() {
        let body = Transport.multipart(data: Data([0xFF, 0xD8, 0xFF]), boundary: "abc")
        let text = String(decoding: body, as: UTF8.self)

        #expect(text.hasPrefix("--abc\r\n"))
        #expect(text.contains(#"name="screenshot"; filename="screenshot.jpg""#))
        #expect(text.contains("Content-Type: image/jpeg"))
        #expect(text.hasSuffix("\r\n--abc--\r\n"))
    }

    @Test("An empty key is refused before anything reaches the network")
    func missingKey() async throws {
        let transport = Transport(endpoint: URL(string: "https://buggie.eu")!, key: "")

        await #expect(throws: BuggieError.missingKey) {
            _ = try await transport.send(Report(title: "x"))
        }
    }

    @Test("The ingest response decodes the server's snake_case upload URL")
    func ingestResponse() throws {
        let body = #"{"id":41,"reference":"ACME-41","upload_url":"https://buggie.eu/u/abc"}"#.data(using: .utf8)!

        let response = try JSONDecoder().decode(IngestResponse.self, from: body)

        #expect(response.reference == "ACME-41")
        #expect(response.uploadURL == "https://buggie.eu/u/abc")
    }
}
