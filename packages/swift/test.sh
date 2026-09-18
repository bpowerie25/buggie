#!/usr/bin/env bash
# Runs the Buggie SDK checks.
#
# With Xcode, that means both the unit tests and a real iOS compile of the UIKit
# layer — which is the only thing that ever type-checks `captureScreen`.
#
# With only the Command Line Tools, swift-testing ships in the toolchain but is not
# on the search path, so it has to be pointed at explicitly; the UIKit layer is
# excluded by `canImport(UIKit)` and goes unchecked.
set -euo pipefail

cd "$(dirname "$0")"

developer_dir="$(xcode-select -p)"

if [[ "$developer_dir" == *"CommandLineTools"* ]]; then
    frameworks="$developer_dir/Library/Developer/Frameworks"
    interop="$developer_dir/Library/Developer/usr/lib"

    echo "==> Command Line Tools only: testing BuggieCore, skipping the UIKit layer."

    exec swift test --disable-xctest \
        -Xswiftc -F -Xswiftc "$frameworks" \
        -Xlinker -F -Xlinker "$frameworks" \
        -Xlinker -rpath -Xlinker "$frameworks" \
        -Xlinker -rpath -Xlinker "$interop" \
        "$@"
fi

echo "==> Unit tests (macOS)"
swift test "$@"

echo
echo "==> Compiling for iOS"
# The only check the UIKit layer ever gets. Without this it is not merely untested,
# it is unseen by a compiler: `canImport(UIKit)` is false everywhere else.
#
# Deliberately not piped through anything that could swallow the exit status. An
# earlier version ended in `|| true` and cheerfully printed "OK" over a build that
# had failed, which is worse than having no check at all.
xcodebuild build \
    -scheme Buggie \
    -destination 'generic/platform=iOS' \
    -quiet

echo "==> iOS build OK"

# The redaction tests need somewhere to actually draw. Xcode no longer ships a
# simulator runtime, so this is skipped loudly rather than silently: a skipped check
# that looks like a passing one is how the screenshot bug survived as long as it did.
simulator="$(xcrun simctl list devices available | grep -m1 -oE '[0-9A-F-]{36}' || true)"

if [[ -z "$simulator" ]]; then
    echo
    echo "==> SKIPPED: redaction tests need an iOS simulator."
    echo "    Install one with: xcodebuild -downloadPlatform iOS"
    exit 0
fi

echo
echo "==> Redaction tests (simulator)"
# Buggie-Package, not Buggie: SwiftPM generates a scheme per library product, and
# only the package-wide one carries the test targets.
# Not -quiet: it suppresses swift-testing's output while leaving XCTest's empty
# bundles visible, so a run that executed nothing reads exactly like a passing one.
xcodebuild test \
    -scheme Buggie-Package \
    -destination "id=$simulator" \
    | grep -E "^(◇|✔|✘)|Test run with|error:" || true

exit "${PIPESTATUS[0]}"
