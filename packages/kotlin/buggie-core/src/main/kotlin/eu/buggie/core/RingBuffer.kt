package eu.buggie.core

/**
 * A fixed-size, thread-safe buffer that keeps the most recent entries.
 *
 * Breadcrumbs and network events are recorded from whatever thread the app happens to
 * be on, and must never grow without bound in a long-running session.
 */
public class RingBuffer<T>(limit: Int) {
    private val limit: Int = maxOf(1, limit)
    private val storage = ArrayDeque<T>()
    private val lock = Any()

    public fun append(element: T) {
        synchronized(lock) {
            storage.addLast(element)

            while (storage.size > limit) storage.removeFirst()
        }
    }

    /** A snapshot. Callers iterate this while other threads are still appending. */
    public val entries: List<T>
        get() = synchronized(lock) { storage.toList() }

    public val count: Int
        get() = synchronized(lock) { storage.size }

    public fun clear() {
        synchronized(lock) { storage.clear() }
    }
}
