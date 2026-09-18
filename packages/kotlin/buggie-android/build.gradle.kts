plugins {
    // AGP 9 brings its own Kotlin support and registers the `kotlin` extension itself;
    // applying org.jetbrains.kotlin.android as well fails at configuration time.
    id("com.android.library")
}

android {
    namespace = "eu.buggie.android"
    compileSdk = 36

    defaultConfig {
        // 24 rather than anything newer: nothing here needs a recent API, and a bug
        // reporter that will not install is a bug reporter nobody uses.
        minSdk = 24
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions {
        unitTests {
            // Robolectric needs the library's own resources: the redaction marker is a
            // tag key, and a tag key has to be a real resource id.
            isIncludeAndroidResources = true
        }
    }
}

kotlin {
    compilerOptions {
        explicitApi()
    }
}

dependencies {
    api(project(":buggie-core"))

    testImplementation(kotlin("test"))
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.16")
    testImplementation("androidx.test:core:1.6.1")
}
