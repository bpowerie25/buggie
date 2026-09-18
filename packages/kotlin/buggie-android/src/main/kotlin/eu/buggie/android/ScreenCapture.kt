package eu.buggie.android

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.os.Looper
import android.view.View
import java.io.ByteArrayOutputStream

/**
 * A JPEG of what is on screen, with anything marked private left out of it.
 *
 * Redacted views are hidden **before** anything is rendered, never painted over
 * afterwards. That is the same rule the web widget arrived at when masks drawn on the
 * finished canvas landed on the labels while the password stayed readable: a mask
 * positioned by separate arithmetic can be silently misaligned, and a redaction that
 * can be silently misaligned is not a redaction.
 *
 * ### Why `View.draw`, and not `PixelCopy`
 *
 * `PixelCopy` is the obvious tool — it is what reads the real, composited window,
 * including surfaces this cannot see. It is also the trap. It copies the buffer the
 * compositor has **already produced**, and that buffer was produced from a frame drawn
 * before the redacted views were hidden. Hiding a view only requests a new frame; it
 * does not wait for one. The copy would come back with the password in it, and nothing
 * anywhere would say so.
 *
 * This is exactly the bug the iOS SDK shipped with in draft: `drawHierarchy` with
 * `afterScreenUpdates: false` reads the same kind of stale, already-composited content.
 * The fix there was to force the commit first. Here the equivalent is not to depend on
 * the frame pipeline at all.
 *
 * `View.draw(Canvas)` walks the hierarchy synchronously and draws the state the views
 * are in *now* — and `ViewGroup` skips children that are not `VISIBLE` as it goes. The
 * hiding and the drawing happen in the same call stack, on the same thread, with no
 * frame in between for anything to go stale.
 *
 * What it costs: content the view system does not draw itself is absent —
 * `SurfaceView`, `TextureView`, video, and anything in another window such as a dialog
 * or the system bars. That is a capture missing content, which is a poor screenshot.
 * The alternative is a capture containing a password, which is an incident.
 */
public object ScreenCapture {
    /** JPEG, like the widget, and the ingest endpoint accepts it. */
    public const val DEFAULT_QUALITY: Int = 80

    /**
     * Capture any view.
     *
     * @return JPEG bytes, or null if the view has not been laid out yet.
     * @throws IllegalStateException if called off the main thread — where the
     *   visibility changes below would race the very frame they are meant to precede.
     */
    @JvmStatic
    @JvmOverloads
    public fun capture(view: View, quality: Int = DEFAULT_QUALITY): ByteArray? {
        check(Looper.myLooper() == Looper.getMainLooper()) {
            "Buggie's screen capture must run on the main thread."
        }

        if (view.width <= 0 || view.height <= 0) return null

        val redacted = view.buggieRedactedViews()
        val was = redacted.map { it.visibility }

        // INVISIBLE, not GONE: GONE triggers a layout pass and the rest of the screen
        // reflows around the hole, so the report shows an arrangement the reporter
        // never saw. INVISIBLE keeps every other view exactly where it was.
        redacted.forEach { it.visibility = View.INVISIBLE }

        try {
            val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
            val canvas = Canvas(bitmap)

            // The window's background is not the view's, and JPEG has no transparency:
            // without this, anything the view does not paint comes out black.
            if (view.background == null) canvas.drawColor(Color.WHITE)

            view.draw(canvas)

            return ByteArrayOutputStream().use { out ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, quality, out)
                bitmap.recycle()

                out.toByteArray()
            }
        } finally {
            // Whatever happened, the app is not left with holes in it.
            redacted.forEachIndexed { index, hidden -> hidden.visibility = was[index] }
        }
    }
}
