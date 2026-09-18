import Foundation

/// What the server is told about a bug.
///
/// Mirrors the widget's payload so one ingest endpoint serves web and native alike:
/// see `docs/DESIGN.md` and `IngestReportRequest` on the server.
public struct Report: Encodable, Sendable {
    public var title: String
    public var body: String?
    public var reporter: Reporter
    public var environment: [String: AnyCodable]
    public var breadcrumbs: [Breadcrumb]
    public var network: [NetworkEvent]
    public var error: CapturedError?
    public var screenshot: Bool

    public init(
        title: String,
        body: String? = nil,
        reporter: Reporter = Reporter(),
        environment: [String: AnyCodable] = [:],
        breadcrumbs: [Breadcrumb] = [],
        network: [NetworkEvent] = [],
        error: CapturedError? = nil,
        screenshot: Bool = false
    ) {
        self.title = title
        self.body = body
        self.reporter = reporter
        self.environment = environment
        self.breadcrumbs = breadcrumbs
        self.network = network
        self.error = error
        self.screenshot = screenshot
    }

    enum CodingKeys: String, CodingKey {
        case title, body, reporter, environment, error, screenshot
        // The server calls this `console`; on a device they are breadcrumbs, but the
        // wire format is shared and the server's name wins.
        case breadcrumbs = "console"
        case network
    }
}

public struct Reporter: Encodable, Sendable {
    public var name: String?
    public var email: String?
    public var ref: String?

    public init(name: String? = nil, email: String? = nil, ref: String? = nil) {
        self.name = name
        self.email = email
        self.ref = ref
    }
}

/// A log line, tagged with when and how bad.
public struct Breadcrumb: Encodable, Sendable {
    public enum Level: String, Encodable, Sendable {
        case debug, info, warn, error
    }

    public let level: Level
    public let message: String
    public let at: Int

    public init(level: Level = .info, message: String, at: Date = Date()) {
        self.level = level
        // Milliseconds since the epoch, matching what the browser sends.
        self.at = Int(at.timeIntervalSince1970 * 1000)
        self.message = Redactor.truncate(message, to: 300)
    }
}

/// Method, URL, status and duration. Never headers, never bodies.
public struct NetworkEvent: Encodable, Sendable {
    public let method: String
    public let url: String
    public let status: Int
    public let duration: Int
    public let at: Int

    public init(method: String, url: String, status: Int, duration: TimeInterval, at: Date = Date()) {
        self.method = method.uppercased()
        self.url = Redactor.safeURL(url)
        self.status = status
        self.duration = Int(duration * 1000)
        self.at = Int(at.timeIntervalSince1970 * 1000)
    }
}

public struct CapturedError: Encodable, Sendable {
    public let message: String
    public let stack: String?

    public init(message: String, stack: String? = nil) {
        self.message = Redactor.truncate(message, to: 2000)
        self.stack = stack.map { Redactor.truncate($0, to: 8000) }
    }
}

/// Lets arbitrary device details ride along in `environment` without a fixed schema.
public struct AnyCodable: Encodable, Sendable {
    private let encode: @Sendable (Encoder) throws -> Void

    public init(_ value: String) { encode = { try value.encode(to: $0) } }
    public init(_ value: Int) { encode = { try value.encode(to: $0) } }
    public init(_ value: Double) { encode = { try value.encode(to: $0) } }
    public init(_ value: Bool) { encode = { try value.encode(to: $0) } }

    public init(_ value: [String: String]) {
        encode = { try value.encode(to: $0) }
    }

    public func encode(to encoder: Encoder) throws {
        try encode(encoder)
    }
}
