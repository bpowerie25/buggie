import Foundation
import Testing
@testable import Buggie
@testable import BuggieCore

/// The device details are read on the main actor and cannot be checked here. What
/// *can* be checked is the assembly around them — which is where the concurrency bug
/// lived, so it is worth pinning down.
@Suite("Report environment")
struct EnvironmentTests {
    private func encode(_ environment: [String: AnyCodable]) throws -> [String: Any] {
        let data = try JSONEncoder().encode(environment)

        return try #require(JSONSerialization.jsonObject(with: data) as? [String: Any])
    }

    @Test("It always says what it is and when")
    func alwaysPresent() throws {
        let json = try encode(Buggie.environment(release: nil, identity: [:]))

        #expect(json["platform"] as? String == "ios")
        #expect(json["captured_at"] as? String != nil)
    }

    @Test("Absent release and identity are omitted, not sent empty")
    func omissions() throws {
        let json = try encode(Buggie.environment(release: nil, identity: [:]))

        #expect(json["release"] == nil)
        #expect(json["identity"] == nil)
    }

    @Test("Release and identity ride along when given")
    func included() throws {
        let json = try encode(Buggie.environment(release: "2.4.1", identity: ["id": "7"]))

        #expect(json["release"] as? String == "2.4.1")
        #expect(json["identity"] as? [String: String] == ["id": "7"])
    }

    @Test("Gathering the environment touches no UIKit accessor off the main actor")
    func fullEnvironmentIsSafe() async throws {
        // On macOS this is the platform-agnostic path; on a device it additionally
        // hops to the main actor. Either way it must be callable from a non-isolated
        // async context, which is the bug this replaced.
        let json = try encode(await Buggie.fullEnvironment(release: "1.0", identity: [:]))

        #expect(json["platform"] as? String == "ios")
        #expect(json["release"] as? String == "1.0")
    }
}
