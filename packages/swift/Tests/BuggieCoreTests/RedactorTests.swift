import Foundation
import Testing
@testable import BuggieCore

/// The rules that keep things off the wire. Same intent as the web widget's
/// redaction tests, because the same promise is being made to the same people.
@Suite("Redaction")
struct RedactorTests {
    @Test("Credential-shaped parameters are replaced, everything else is kept")
    func credentialParameters() {
        let safe = Redactor.safeURL("https://acme.test/reset?token=supersecret&page=2")

        #expect(!safe.contains("supersecret"))
        #expect(safe.contains("redacted"))
        // The rest survives; a redacted report still has to be a useful one.
        #expect(safe.contains("page=2"))
    }

    @Test("Every credential name is caught regardless of casing", arguments: [
        "token", "KEY", "Secret", "password", "passwd", "auth", "session",
        "sig", "signature", "access_token", "api_key", "code",
    ])
    func everySecretName(_ name: String) {
        #expect(!Redactor.safeURL("https://acme.test/x?\(name)=leaked").contains("leaked"))
    }

    @Test("Fragments are dropped entirely")
    func fragments() {
        // OAuth flows park tokens in the fragment and it is never useful to us.
        let safe = Redactor.safeURL("https://acme.test/callback#access_token=leaked&state=x")

        #expect(!safe.contains("leaked"))
        #expect(!safe.contains("#"))
    }

    @Test("Unparseable input does not leak itself")
    func unparseable() {
        #expect(Redactor.safeURL("http://  /?token=leaked") == "[unparseable url]")
    }

    @Test("Truncation is bounded and leaves short values alone")
    func truncation() {
        #expect(Redactor.truncate(String(repeating: "x", count: 5000), to: 100).count == 101)
        #expect(Redactor.truncate("short", to: 100) == "short")
    }

    @Test("Identity keeps only what was actually given")
    func identity() {
        let identity = Redactor.identity(["email": "a@b.test", "name": "", "id": "7"])

        #expect(identity == ["email": "a@b.test", "id": "7"])
    }
}
