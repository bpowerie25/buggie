import java.util.Properties

pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "buggie"

include(":buggie-core")

/**
 * The Android SDK, if this machine has one.
 *
 * `buggie-android` is included only when it does. The point of the split is that the
 * code deciding what leaves a phone can be built and tested on any machine, and a
 * root project that cannot configure without an Android SDK would give that away.
 */
val androidSdk: String? = sequenceOf(
    System.getenv("ANDROID_HOME"),
    System.getenv("ANDROID_SDK_ROOT"),
    file("local.properties").takeIf { it.exists() }
        ?.let { Properties().apply { it.inputStream().use(::load) }.getProperty("sdk.dir") },
    System.getProperty("user.home") + "/Library/Android/sdk",
).filterNotNull().firstOrNull { file(it).isDirectory }

if (androidSdk != null) {
    include(":buggie-android")
} else {
    // Loud, not silent: a skipped module that reads like a passing one is how an
    // untested claim survives.
    gradle.rootProject {
        logger.lifecycle("==> SKIPPING :buggie-android — no Android SDK found. Set ANDROID_HOME.")
    }
}
