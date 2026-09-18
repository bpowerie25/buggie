package eu.buggie.android

import android.app.Activity
import android.content.Context
import androidx.test.core.app.ApplicationProvider
import eu.buggie.core.BuggieClient
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

/**
 * The device details, and the seam they arrive through.
 *
 * Worth pinning down because on iOS this was where three bugs lived: the wrong screen,
 * the wrong thread, and a payload builder that could not be tested because it read the
 * device directly.
 */
@RunWith(RobolectricTestRunner::class)
class EnvironmentTest {
    private val context: Context get() = ApplicationProvider.getApplicationContext()

    @Test
    fun `it says what the device is`() {
        val environment = AndroidEnvironment(context).environment()

        assertTrue((environment["os"] as String).startsWith("Android "))
        assertNotNull(environment["device"])
        assertNotNull(environment["locale"])
        assertTrue((environment["viewport"] as String).contains("x"))
        assertTrue(environment["pixel_ratio"] is Double)
        assertTrue(environment["app_build"] is Long)
    }

    @Test
    fun `the viewport comes from the window in front, not from the display`() {
        // Under split screen the two disagree, and the app's own window is the one the
        // reporter was looking at.
        val activity = Robolectric.buildActivity(Activity::class.java).setup().get()

        val fromActivity = AndroidEnvironment(context) { activity }.environment()["viewport"]

        assertNotNull(fromActivity)
        assertTrue((fromActivity as String).matches(Regex("\\d+x\\d+")))
    }

    @Test
    fun `the details ride along in a report rather than being read while one is sent`() {
        val client = BuggieClient(AndroidEnvironment(context))

        val environment = client.buildReport("Pay now does nothing").environment

        // Ours, and the device's, in one map.
        assertEquals("android", environment["platform"])
        assertTrue((environment["os"] as String).startsWith("Android "))
    }

    @Test
    fun `the foreground activity is released when it is destroyed`() {
        // An SDK that outlives an activity is a leak the host app gets blamed for.
        val controller = Robolectric.buildActivity(Activity::class.java).setup()
        ForegroundActivity.install(controller.get().application)
        controller.resume()

        assertEquals(controller.get(), ForegroundActivity.activity)

        controller.pause().stop().destroy()

        assertNull(ForegroundActivity.activity)
    }
}
