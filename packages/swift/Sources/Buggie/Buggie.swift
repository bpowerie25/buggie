import Foundation
import BuggieCore

#if canImport(UIKit)
import UIKit
#endif

/// The Buggie client.
///
/// Everything that can be reasoned about without a screen lives in `BuggieCore` and
/// is unit tested. This type holds the context an app accumulates and the one piece
/// that genuinely needs a device: the screenshot.
///
/// There is deliberately no report sheet here. What a bug report should look like is
/// the app's decision, not ours, and a sheet we shipped would be the first thing
/// anyone had to fight. Present your own UI and call `report(title:body:)`.
public final class Buggie: @unchecked Sendable {
    public static let shared = Buggie()

    private var transport: Transport?
    private let breadcrumbs = RingBuffer<Breadcrumb>(limit: 50)
    private let network = RingBuffer<NetworkEvent>(limit: 30)
    private var identity: [String: String] = [:]
    private var release: String?
    private var lastError: CapturedError?
    private let lock = NSLock()

    private init() {}

    // MARK: - Setting up

    /// Point the client at an instance.
    ///
    /// - Parameters:
    ///   - key: the public widget key from project settings.
    ///   - endpoint: where Buggie lives. Defaults to the hosted service.
    public func start(key: String, endpoint: URL = URL(string: "https://buggie.eu")!) {
        lock.lock()
        defer { lock.unlock() }

        transport = Transport(endpoint: endpoint, key: key)
    }

    public func identify(id: String? = nil, email: String? = nil, name: String? = nil, extra: [String: String] = [:]) {
        lock.lock()
        defer { lock.unlock() }

        var merged = extra
        if let id { merged["id"] = id }
        if let email { merged["email"] = email }
        if let name { merged["name"] = name }

        identity.merge(Redactor.identity(merged)) { _, new in new }
    }

    public func setRelease(_ value: String) {
        lock.lock()
        defer { lock.unlock() }

        release = value
    }

    // MARK: - Context

    /// Record something that might explain a later bug.
    public func leaveBreadcrumb(_ message: String, level: Breadcrumb.Level = .info) {
        breadcrumbs.append(Breadcrumb(level: level, message: message))
    }

    /// Record a request. Method, URL, status and duration only — never headers or
    /// bodies, the same as the web widget.
    public func record(method: String, url: String, status: Int, duration: TimeInterval) {
        network.append(NetworkEvent(method: method, url: url, status: status, duration: duration))
    }

    /// Attach an error so the next report carries it, and so the server can group
    /// repeat occurrences by fingerprint.
    public func record(error: Error, stack: String? = nil) {
        lock.lock()
        defer { lock.unlock() }

        lastError = CapturedError(
            message: String(describing: error),
            stack: stack ?? Thread.callStackSymbols.joined(separator: "\n")
        )
    }

    // MARK: - Reporting

    /// Send a report. `screenshot` is JPEG data the host app supplies — typically
    /// from `captureScreen()`, but anything will do.
    @discardableResult
    public func report(
        title: String,
        body: String? = nil,
        reporterEmail: String? = nil,
        screenshot: Data? = nil
    ) async throws -> IngestResponse {
        // Taken in one go through a synchronous helper: holding a lock across an
        // await is an error under the Swift 6 language mode, and a report should
        // describe the moment it was raised, not a state that drifted mid-send.
        let state = snapshot()

        guard let transport = state.transport else { throw BuggieError.missingKey }

        let report = Report(
            title: title,
            body: body,
            reporter: Reporter(
                name: state.identity["name"],
                email: reporterEmail ?? state.identity["email"],
                ref: state.identity["id"]
            ),
            environment: await Self.fullEnvironment(release: state.release, identity: state.identity),
            breadcrumbs: breadcrumbs.entries,
            network: network.entries,
            error: state.error,
            screenshot: screenshot != nil
        )

        let response = try await transport.send(report)

        if let screenshot, let uploadURL = response.uploadURL {
            // A failed image must not fail an otherwise good report.
            try? await transport.upload(screenshot: screenshot, to: uploadURL)
        }

        return response
    }

    struct Snapshot {
        let transport: Transport?
        let identity: [String: String]
        let release: String?
        let error: CapturedError?
    }

    private func snapshot() -> Snapshot {
        lock.lock()
        defer { lock.unlock() }

        return Snapshot(transport: transport, identity: identity, release: release, error: lastError)
    }

    /// The device details have to be read on the main actor, so they are fetched with
    /// a hop rather than by making the whole of `report()` main-actor isolated — an
    /// app should be able to file a report from wherever it noticed the problem.
    static func fullEnvironment(release: String?, identity: [String: String]) async -> [String: AnyCodable] {
        var environment = Self.environment(release: release, identity: identity)

        #if canImport(UIKit)
        let device = await MainActor.run { Self.deviceEnvironment() }
        environment.merge(device) { _, new in new }
        #endif

        return environment
    }

    /// What can be said about the report without touching UIKit.
    ///
    /// Deliberately free of device details: every UIKit accessor below is main-actor
    /// isolated, and this runs on whatever thread `report()` was called from.
    static func environment(release: String?, identity: [String: String]) -> [String: AnyCodable] {
        var environment: [String: AnyCodable] = [
            "platform": AnyCodable("ios"),
            "captured_at": AnyCodable(ISO8601DateFormatter().string(from: Date())),
        ]

        if let release { environment["release"] = AnyCodable(release) }
        if !identity.isEmpty { environment["identity"] = AnyCodable(identity) }

        if let bundle = Bundle.main.infoDictionary {
            if let version = bundle["CFBundleShortVersionString"] as? String {
                environment["app_version"] = AnyCodable(version)
            }
            if let build = bundle["CFBundleVersion"] as? String {
                environment["app_build"] = AnyCodable(build)
            }
        }

        return environment
    }
}

#if canImport(UIKit)
extension Buggie {
    /// The window a report is being filed about.
    @MainActor
    static var keyWindow: UIWindow? {
        UIApplication.shared.connectedScenes
            .compactMap { $0 as? UIWindowScene }
            .flatMap(\.windows)
            .first { $0.isKeyWindow }
    }

    /// What the device can tell us about itself. Main-actor isolated because every
    /// one of these accessors is.
    @MainActor
    static func deviceEnvironment() -> [String: AnyCodable] {
        let device = UIDevice.current
        var environment: [String: AnyCodable] = [
            "os": AnyCodable("\(device.systemName) \(device.systemVersion)"),
            "device": AnyCodable(device.model),
        ]

        // Taken from the window rather than UIScreen.main, which says nothing useful
        // on an iPad running two scenes side by side.
        if let window = keyWindow {
            let size = window.bounds.size
            environment["viewport"] = AnyCodable("\(Int(size.width))x\(Int(size.height))")
            environment["pixel_ratio"] = AnyCodable(Double(window.screen.scale))
        }

        return environment
    }

    /// A JPEG of what is on screen, with anything marked private left out of it.
    ///
    /// Views are hidden **before** rendering rather than masked afterwards, for the
    /// same reason the web widget restyles the DOM instead of painting on the canvas:
    /// a mask positioned by separate arithmetic can be silently misaligned, and a
    /// redaction that can be silently misaligned is not a redaction.
    @MainActor
    public func captureScreen(compression: CGFloat = 0.8) -> Data? {
        guard let window = Self.keyWindow else { return nil }

        let redacted = window.buggieRedactedSubviews()
        redacted.forEach { $0.isHidden = true }
        defer { redacted.forEach { $0.isHidden = false } }

        let renderer = UIGraphicsImageRenderer(bounds: window.bounds)
        let image = renderer.image { context in
            // `afterScreenUpdates: true` is load-bearing, not a default left alone.
            // With `false`, UIKit draws the content already composited for the screen
            // — composited before those views were hidden. The redaction would be
            // absent from the image and nothing would say so. It costs a commit, and
            // it is the whole reason this function can be trusted.
            let drawn = window.drawHierarchy(in: window.bounds, afterScreenUpdates: true)

            // Snapshotting can refuse; a window backed by a protected surface returns
            // false. The layer tree keeps a report possible, and it honours
            // `isHidden` too, so nothing leaks either way.
            if !drawn {
                window.layer.render(in: context.cgContext)
            }
        }

        return image.jpegData(compressionQuality: compression)
    }
}

public extension UIView {
    /// Mark a view so it never appears in a screenshot — the native equivalent of
    /// `data-buggie-redact`.
    var buggieRedacted: Bool {
        get { layer.value(forKey: "buggie.redacted") as? Bool ?? false }
        set { layer.setValue(newValue, forKey: "buggie.redacted") }
    }

    internal func buggieRedactedSubviews() -> [UIView] {
        var found: [UIView] = []

        if buggieRedacted { found.append(self) }

        // Secure text fields are redacted without anybody having to remember.
        if let field = self as? UITextField, field.isSecureTextEntry {
            found.append(field)
        }

        for subview in subviews {
            found.append(contentsOf: subview.buggieRedactedSubviews())
        }

        return found
    }
}
#endif
