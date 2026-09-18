import Foundation

/// A fixed-size, thread-safe buffer that keeps the most recent entries.
///
/// Breadcrumbs and network events are recorded from whatever thread the app happens
/// to be on, and must never grow without bound in a long-running session.
public final class RingBuffer<Element>: @unchecked Sendable {
    private let limit: Int
    private var storage: [Element] = []
    private let lock = NSLock()

    public init(limit: Int) {
        self.limit = max(1, limit)
        storage.reserveCapacity(self.limit)
    }

    public func append(_ element: Element) {
        lock.lock()
        defer { lock.unlock() }

        storage.append(element)

        if storage.count > limit {
            storage.removeFirst(storage.count - limit)
        }
    }

    public var entries: [Element] {
        lock.lock()
        defer { lock.unlock() }

        return storage
    }

    public var count: Int {
        lock.lock()
        defer { lock.unlock() }

        return storage.count
    }

    public func clear() {
        lock.lock()
        defer { lock.unlock() }

        storage.removeAll(keepingCapacity: true)
    }
}
