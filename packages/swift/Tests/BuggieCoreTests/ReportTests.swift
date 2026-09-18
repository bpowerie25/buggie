import Foundation
import Testing
@testable import BuggieCore

/// The payload has to match what the server's IngestReportRequest accepts, or native
/// reports fail validation in a way nobody notices until a customer complains.
@Suite("Report payload")
struct ReportTests {
    private func encode(_ report: Report) throws -> [String: Any] {
        let data = try JSONEncoder().encode(report)

        return try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    @Test("It encodes the keys the server expects")
    func wireFormat() throws {
        let json = try encode(Report(
            title: "Pay now does nothing",
            body: "Nothing happens.",
            reporter: Reporter(name: "Ana", email: "ana@shopper.test", ref: "4821"),
            environment: ["platform": AnyCodable("ios")],
            breadcrumbs: [Breadcrumb(level: .error, message: "boom")],
            network: [NetworkEvent(method: "post", url: "https://acme.test/pay", status: 500, duration: 0.412)],
            error: CapturedError(message: "nil unwrap"),
            screenshot: true
        ))

        #expect(json["title"] as? String == "Pay now does nothing")
        #expect(json["screenshot"] as? Bool == true)

        // The server calls breadcrumbs "console"; the wire format is shared with the web widget.
        #expect(json["console"] != nil)
        #expect(json["breadcrumbs"] == nil)

        let reporter = try #require(json["reporter"] as? [String: Any])
        #expect(reporter["email"] as? String == "ana@shopper.test")
        #expect(reporter["ref"] as? String == "4821")

        let environment = try #require(json["environment"] as? [String: Any])
        #expect(environment["platform"] as? String == "ios")
    }

    @Test("Network events are normalised and redacted on the way in")
    func networkEvents() {
        let event = NetworkEvent(
            method: "post",
            url: "https://acme.test/pay?api_key=leaked",
            status: 500,
            duration: 1.5
        )

        #expect(event.method == "POST")
        #expect(event.duration == 1500) // milliseconds, as the browser sends
        #expect(!event.url.contains("leaked"))
    }

    @Test("A breadcrumb cannot be unbounded")
    func breadcrumbBounds() {
        #expect(Breadcrumb(message: String(repeating: "x", count: 1000)).message.count == 301)
    }

    @Test("Timestamps are milliseconds since the epoch")
    func timestamps() {
        let at = Date(timeIntervalSince1970: 1_700_000_000.5)

        #expect(Breadcrumb(message: "x", at: at).at == 1_700_000_000_500)
    }

    @Test("A captured error is truncated but keeps its stack")
    func capturedError() {
        let error = CapturedError(message: String(repeating: "m", count: 3000), stack: "Frame one")

        #expect(error.message.count == 2001)
        #expect(error.stack == "Frame one")
    }

    @Test("An absent body is omitted rather than sent as null")
    func omittedFields() throws {
        let json = try encode(Report(title: "Just a title"))

        #expect(json["body"] == nil)
        #expect(json["screenshot"] as? Bool == false)
    }
}
