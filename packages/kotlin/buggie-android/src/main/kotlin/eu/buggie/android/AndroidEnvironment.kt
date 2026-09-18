package eu.buggie.android

import android.app.Activity
import android.app.Application
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import eu.buggie.core.EnvironmentSource
import java.lang.ref.WeakReference
import java.util.Locale

/**
 * What the device can say about itself.
 *
 * Read through [EnvironmentSource] rather than from inside the payload builder, so that
 * everything deciding what goes into a report stays testable without a device. On iOS
 * the same code, inlined, was a thread-safety bug as well as an untestable one.
 */
internal class AndroidEnvironment(
    context: Context,
    private val foreground: () -> Activity? = { null },
) : EnvironmentSource {
    private val application = context.applicationContext

    override fun environment(): Map<String, Any?> = buildMap {
        put("os", "Android ${Build.VERSION.RELEASE}")
        put("device", "${Build.MANUFACTURER} ${Build.MODEL}")
        put("locale", Locale.getDefault().toLanguageTag())

        // The activity's own resources, not the application's: under split screen or a
        // freeform window they disagree, and the app's window is the one the reporter
        // was looking at. The iOS SDK learnt this as `UIScreen.main` being the wrong
        // screen on an iPad running two scenes side by side.
        val metrics = (foreground() ?: application).resources.displayMetrics

        put("viewport", "${(metrics.widthPixels / metrics.density).toInt()}x${(metrics.heightPixels / metrics.density).toInt()}")
        put("pixel_ratio", metrics.density.toDouble())

        try {
            val info = application.packageManager.getPackageInfo(application.packageName, 0)

            put("app_version", info.versionName ?: "")
            put(
                "app_build",
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    info.longVersionCode
                } else {
                    @Suppress("DEPRECATION")
                    info.versionCode.toLong()
                },
            )
        } catch (_: PackageManager.NameNotFoundException) {
            // An app that cannot find itself is not a reason to lose the report.
        }
    }
}

/**
 * The activity currently in front, weakly held.
 *
 * Needed for two things: the window to capture, and the metrics of the window the
 * reporter is actually looking at. Weak, because an SDK that keeps an activity alive
 * after it is finished is a memory leak the host app gets blamed for.
 */
internal object ForegroundActivity : Application.ActivityLifecycleCallbacks {
    @Volatile
    private var current: WeakReference<Activity>? = null

    val activity: Activity?
        get() = current?.get()?.takeUnless { it.isFinishing }

    fun install(application: Application) {
        application.unregisterActivityLifecycleCallbacks(this)
        application.registerActivityLifecycleCallbacks(this)
    }

    override fun onActivityResumed(activity: Activity) {
        current = WeakReference(activity)
    }

    override fun onActivityDestroyed(activity: Activity) {
        // Cleared on destroy rather than on pause: a dialog or a permission prompt
        // pauses the activity, and a report filed from one still belongs to it.
        if (current?.get() === activity) current = null
    }

    override fun onActivityCreated(activity: Activity, state: Bundle?) = Unit

    override fun onActivityStarted(activity: Activity) = Unit

    override fun onActivityPaused(activity: Activity) = Unit

    override fun onActivityStopped(activity: Activity) = Unit

    override fun onActivitySaveInstanceState(activity: Activity, state: Bundle) = Unit
}
