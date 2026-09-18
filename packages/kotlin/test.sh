#!/usr/bin/env bash
# Runs the Buggie SDK checks.
#
# `buggie-core` is plain Kotlin/JVM and needs only a JDK. It is the part that decides
# what leaves a phone, so it has to be checkable on any machine.
#
# `buggie-android` needs an Android SDK, and its tests additionally need a JDK that
# Robolectric can instrument. Where either is missing the checks are skipped loudly
# rather than passed over: a skipped check that reads like a passing one is how an
# untested claim survives.
#
# Nothing here is piped through anything that could swallow an exit status. The iOS
# script once ended in `|| true` and cheerfully printed "OK" over a build that had
# failed, which is worse than having no check at all.
set -euo pipefail

cd "$(dirname "$0")"

# Robolectric rewrites bytecode with a bundled ASM, and ASM refuses class files newer
# than it knows about. On a JDK past this it fails with an unexplained
# IllegalArgumentException from ClassReader, which looks like a broken test rather than
# a broken toolchain.
#
# 17 is known to work here and 27 is known not to. 21 is the usual upper bound for this
# version of Robolectric; if it turns out not to be, this is the line to lower.
readonly ROBOLECTRIC_MAX_JDK=21

java_major() {
    "$1/bin/java" -version 2>&1 | head -1 | sed -E 's/.*version "([0-9]+).*/\1/'
}

# Homebrew's openjdk is keg-only, so `java` on a Mac's PATH is Apple's stub, which
# reports no runtime at all however many JDKs are installed. Look where they really are
# before believing it. A JDK Robolectric can use is preferred, so that one run checks
# everything.
if [[ -z "${JAVA_HOME:-}" ]]; then
    for candidate in \
        /opt/homebrew/opt/openjdk@17/libexec/openjdk.jdk/Contents/Home \
        /opt/homebrew/opt/openjdk@21/libexec/openjdk.jdk/Contents/Home \
        "$(/usr/libexec/java_home -v 17 2>/dev/null || true)" \
        "$(/usr/libexec/java_home -v 21 2>/dev/null || true)" \
        /opt/homebrew/opt/openjdk/libexec/openjdk.jdk/Contents/Home \
        /usr/lib/jvm/default-java \
        "$(/usr/libexec/java_home 2>/dev/null || true)"
    do
        if [[ -n "$candidate" && -x "$candidate/bin/java" ]]; then
            export JAVA_HOME="$candidate"
            break
        fi
    done
fi

if [[ -z "${JAVA_HOME:-}" ]]; then
    echo "==> FAILED: no JDK found. Install one with: brew install openjdk@17" >&2
    exit 1
fi

export PATH="$JAVA_HOME/bin:$PATH"
jdk_major="$(java_major "$JAVA_HOME")"

echo "==> JDK $jdk_major: $JAVA_HOME"
echo
echo "==> Unit tests (buggie-core)"
./gradlew :buggie-core:test "$@"

if [[ -z "${ANDROID_HOME:-}" ]]; then
    for candidate in "$HOME/Library/Android/sdk" "$HOME/Android/Sdk"; do
        if [[ -d "$candidate" ]]; then
            export ANDROID_HOME="$candidate"
            break
        fi
    done
fi

if [[ -z "${ANDROID_HOME:-}" ]]; then
    echo
    echo "==> SKIPPED: buggie-android needs an Android SDK and there is none here."
    echo "    That means the screenshot redaction — the one claim in this SDK that"
    echo "    must not be taken on trust — has NOT been checked."
    echo "    Install one through Android Studio, or set ANDROID_HOME."
    exit 0
fi

echo
echo "==> Release build (buggie-android)"
./gradlew :buggie-android:assembleRelease "$@"

if (( jdk_major > ROBOLECTRIC_MAX_JDK )); then
    echo
    echo "==> SKIPPED: the buggie-android tests need a JDK of $ROBOLECTRIC_MAX_JDK or"
    echo "    older, and Gradle is running on $jdk_major. Robolectric cannot instrument"
    echo "    newer class files."
    echo "    That means the screenshot redaction has NOT been checked — only compiled."
    echo "    Re-run with: JAVA_HOME=\$(/usr/libexec/java_home -v 17) ./test.sh"
    exit 0
fi

echo
echo "==> Unit tests (buggie-android)"
# Robolectric, in native graphics mode, so the redaction tests look at real pixels.
# With the stubbed canvas every one of them would pass against a blank image.
./gradlew :buggie-android:testDebugUnitTest "$@"

echo
echo "==> OK"
