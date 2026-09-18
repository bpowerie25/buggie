import Foundation
import Testing
@testable import BuggieCore

@Suite("Ring buffer")
struct RingBufferTests {
    @Test("It keeps only the most recent entries")
    func recency() {
        let buffer = RingBuffer<Int>(limit: 3)

        (1 ... 10).forEach(buffer.append)

        #expect(buffer.entries == [8, 9, 10])
    }

    @Test("It survives concurrent writers")
    func concurrency() {
        // Breadcrumbs are recorded from whatever thread the app happens to be on.
        let buffer = RingBuffer<Int>(limit: 100)
        let group = DispatchGroup()

        for i in 0 ..< 500 {
            DispatchQueue.global().async(group: group) { buffer.append(i) }
        }

        group.wait()

        #expect(buffer.count == 100)
    }

    @Test("A nonsensical limit still holds something")
    func degenerateLimit() {
        let buffer = RingBuffer<Int>(limit: 0)
        buffer.append(1)
        buffer.append(2)

        #expect(buffer.entries == [2])
    }

    @Test("Clearing empties it")
    func clearing() {
        let buffer = RingBuffer<Int>(limit: 10)
        (1 ... 5).forEach(buffer.append)
        buffer.clear()

        #expect(buffer.count == 0)
    }
}
