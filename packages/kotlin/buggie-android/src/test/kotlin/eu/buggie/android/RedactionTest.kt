package eu.buggie.android

import android.content.Context
import android.graphics.BitmapFactory
import android.graphics.Color
import android.text.InputType
import android.view.View
import android.view.View.MeasureSpec
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.TextView
import androidx.test.core.app.ApplicationProvider
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.GraphicsMode
import java.util.concurrent.Callable
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNotNull
import kotlin.test.assertTrue

/**
 * The claim this SDK cannot afford to get wrong, checked against actual pixels.
 *
 * Reasoning about the drawing is how the bug got into the iOS SDK: the code looked
 * correct, hid the views before rendering, and still produced an image with the
 * password in it, because the render read a buffer composited before the hiding.
 * Nothing short of inspecting the output would have caught it.
 *
 * `GraphicsMode.NATIVE` is why this can run on a laptop at all — Robolectric rasterises
 * through the real Android graphics code rather than stubbing the canvas out. With the
 * stubs, every one of these tests would pass against a blank image.
 */
@RunWith(RobolectricTestRunner::class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class RedactionTest {
    private val context: Context get() = ApplicationProvider.getApplicationContext()

    /** A red view inside a white one. Red is the secret. */
    private fun hierarchy(mark: (View) -> Unit = {}): FrameLayout {
        val container = FrameLayout(context)
        container.setBackgroundColor(Color.WHITE)

        val secret = View(context)
        secret.setBackgroundColor(Color.RED)
        mark(secret)

        container.addView(
            secret,
            FrameLayout.LayoutParams(40, 40).apply {
                leftMargin = 20
                topMargin = 20
            },
        )

        container.measure(
            MeasureSpec.makeMeasureSpec(80, MeasureSpec.EXACTLY),
            MeasureSpec.makeMeasureSpec(80, MeasureSpec.EXACTLY),
        )
        container.layout(0, 0, 80, 80)

        return container
    }

    /** Whether the captured image contains any strongly red pixel. */
    private fun containsRed(data: ByteArray): Boolean {
        val bitmap = assertNotNull(
            BitmapFactory.decodeByteArray(data, 0, data.size),
            "The capture did not decode as an image.",
        )

        for (x in 0 until bitmap.width) {
            for (y in 0 until bitmap.height) {
                val pixel = bitmap.getPixel(x, y)

                // Generous thresholds: JPEG is lossy and the edges will be muddied.
                if (Color.red(pixel) > 180 && Color.green(pixel) < 90 && Color.blue(pixel) < 90) {
                    return true
                }
            }
        }

        return false
    }

    @Test
    fun `a marked view does not appear in the capture`() {
        val data = assertNotNull(ScreenCapture.capture(hierarchy { it.buggieRedacted = true }))

        assertFalse(containsRed(data), "A redacted view was in the screenshot.")
    }

    @Test
    fun `the same view does appear when it is not marked`() {
        // The control, and it is not a formality: it is the only test here that catches
        // the failure this file exists for.
        //
        // On iOS, reverting the capture to the stale-buffer version left the test above
        // — the one that sounds like the important one — *passing*, because nothing was
        // drawn at all and a blank image contains no red. A negative assertion is
        // satisfied by an empty result. Delete this and the suite stays green while the
        // screenshot is broken.
        val data = assertNotNull(ScreenCapture.capture(hierarchy()))

        assertTrue(containsRed(data), "Nothing was drawn, so the test above proves nothing.")
    }

    @Test
    fun `a view marked in a layout file is redacted too`() {
        val data = assertNotNull(ScreenCapture.capture(hierarchy { it.tag = BUGGIE_REDACT_TAG }))

        assertFalse(containsRed(data))
    }

    @Test
    fun `the capture is restored afterwards, so the app is not left broken`() {
        val container = hierarchy { it.buggieRedacted = true }
        val secret = container.getChildAt(0)

        ScreenCapture.capture(container)

        assertEquals(View.VISIBLE, secret.visibility)
    }

    @Test
    fun `a view the app had already hidden stays hidden`() {
        // The restore puts back what was there, not what we assume was there.
        val container = hierarchy {
            it.buggieRedacted = true
            it.visibility = View.GONE
        }

        ScreenCapture.capture(container)

        assertEquals(View.GONE, container.getChildAt(0).visibility)
    }

    @Test
    fun `a password field is redacted without anyone marking it`() {
        val container = FrameLayout(context)
        val field = EditText(context)
        field.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        container.addView(field)

        assertContentEquals(listOf(field), container.buggieRedactedViews())
    }

    @Test
    fun `every password variation counts, and ordinary fields do not`() {
        val passwords = mapOf(
            "text" to (InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD),
            "visible" to (InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD),
            "web" to (InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD),
            "numeric PIN" to (InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_VARIATION_PASSWORD),
        )

        for ((name, type) in passwords) {
            val field = EditText(context).apply { inputType = type }

            assertTrue(field.isPasswordField, "A $name password field was not detected.")
        }

        // The control. Over-redacting an email box would quietly hide the field that
        // most often explains the bug — and TYPE_TEXT_VARIATION_URI shares its bit
        // pattern with the numeric password variation, so this is a live risk.
        val ordinary = mapOf(
            "email" to (InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS),
            "URI" to (InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI),
            "number" to InputType.TYPE_CLASS_NUMBER,
        )

        for ((name, type) in ordinary) {
            val field = EditText(context).apply { inputType = type }

            assertFalse(field.isPasswordField, "An ordinary $name field was treated as a password.")
        }

        assertFalse(TextView(context).isPasswordField)
    }

    @Test
    fun `marking is found at any depth, and unmarked views are left alone`() {
        val root = FrameLayout(context)
        val middle = FrameLayout(context)
        val deep = View(context).apply { buggieRedacted = true }

        middle.addView(deep)
        root.addView(middle)
        root.addView(View(context))

        assertContentEquals(listOf(deep), root.buggieRedactedViews())
    }

    @Test
    fun `a marked container is taken whole, not walked into`() {
        val root = FrameLayout(context)
        val marked = FrameLayout(context).apply { buggieRedacted = true }
        marked.addView(EditText(context).apply {
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        })
        root.addView(marked)

        // Hiding the parent hides the child; listing both only makes the restore harder.
        assertContentEquals(listOf(marked), root.buggieRedactedViews())
    }

    @Test
    fun `a view with no size does not pretend to be a screenshot`() {
        assertEquals(null, ScreenCapture.capture(View(context)))
    }

    @Test
    fun `capturing off the main thread is refused rather than racing the frame it needs`() {
        val container = hierarchy { it.buggieRedacted = true }
        val pool = Executors.newSingleThreadExecutor()

        val thrown = pool.submit(Callable {
            runCatching { ScreenCapture.capture(container) }.exceptionOrNull()
        }).get(10, TimeUnit.SECONDS)

        pool.shutdown()

        assertTrue(thrown is IllegalStateException, "Expected a refusal, got $thrown")
    }
}
