package eu.buggie.core

import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.test.assertEquals
import kotlin.test.assertTrue

@DisplayName("Ring buffer")
class RingBufferTest {
    @Test
    fun `it keeps only the most recent entries`() {
        val buffer = RingBuffer<Int>(3)

        (1..10).forEach(buffer::append)

        assertEquals(listOf(8, 9, 10), buffer.entries)
    }

    @Test
    fun `it survives concurrent writers`() {
        // Breadcrumbs are recorded from whatever thread the app happens to be on.
        val buffer = RingBuffer<Int>(100)
        val pool = Executors.newFixedThreadPool(8)
        val start = CountDownLatch(1)
        val done = CountDownLatch(500)

        repeat(500) { i ->
            pool.execute {
                start.await()
                buffer.append(i)
                done.countDown()
            }
        }

        start.countDown()
        assertTrue(done.await(10, TimeUnit.SECONDS), "The writers did not finish.")
        pool.shutdown()

        assertEquals(100, buffer.count)
    }

    @Test
    fun `reading while writing never sees a torn buffer`() {
        // `entries` hands out a snapshot for exactly this reason: a report is built on
        // one thread while the app carries on logging from others.
        val buffer = RingBuffer<Int>(50)
        val pool = Executors.newFixedThreadPool(4)
        val done = CountDownLatch(4)

        repeat(2) { pool.execute { repeat(2000) { i -> buffer.append(i) }; done.countDown() } }
        repeat(2) {
            pool.execute {
                repeat(2000) { assertTrue(buffer.entries.size <= 50) }
                done.countDown()
            }
        }

        assertTrue(done.await(10, TimeUnit.SECONDS), "The workers did not finish.")
        pool.shutdown()
    }

    @Test
    fun `a nonsensical limit still holds something`() {
        val buffer = RingBuffer<Int>(0)
        buffer.append(1)
        buffer.append(2)

        assertEquals(listOf(2), buffer.entries)
    }

    @Test
    fun `clearing empties it`() {
        val buffer = RingBuffer<Int>(10)
        (1..5).forEach(buffer::append)
        buffer.clear()

        assertEquals(0, buffer.count)
    }
}
