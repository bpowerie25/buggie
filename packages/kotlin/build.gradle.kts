plugins {
    kotlin("jvm") version "2.4.20" apply false
    id("com.android.library") version "9.3.0" apply false
}

// The coordinates an app asks for. Nothing is published to a repository yet, so an
// app builds the SDK from a checkout with includeBuild(), and Gradle swaps these in
// for the dependency by matching group and name. See README.md.
subprojects {
    group = "eu.buggie"
    version = "0.1.0"
}
