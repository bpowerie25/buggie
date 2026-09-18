import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    kotlin("jvm")
}

kotlin {
    compilerOptions {
        // 17, because this module is also compiled into Android apps by
        // :buggie-android and the bytecode has to be something they can consume.
        jvmTarget = JvmTarget.JVM_17

        // The bytecode version alone would not stop this compiling against a newer
        // JDK's class library and then failing on a phone. -Xjdk-release pins the API
        // surface too, so the build is correct on whatever JDK happens to run Gradle
        // rather than needing one exact toolchain installed.
        freeCompilerArgs.add("-Xjdk-release=17")

        // The library is consumed from Java and Kotlin alike; explicit visibility and
        // return types stop an accidental widening of the public surface.
        explicitApi()
    }
}

java {
    sourceCompatibility = JavaVersion.VERSION_17
    targetCompatibility = JavaVersion.VERSION_17
}

dependencies {
    testImplementation(kotlin("test"))
    testImplementation("org.junit.jupiter:junit-jupiter:5.11.4")
    testRuntimeOnly("org.junit.platform:junit-platform-launcher")
}

tasks.test {
    useJUnitPlatform()
    testLogging {
        events("passed", "failed", "skipped")
        showStandardStreams = false
    }
}
