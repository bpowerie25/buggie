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
xcodebuild build \
    -scheme Buggie \
    -destination 'generic/platform=iOS' \
    -quiet \
    | grep -v '^$' || true

echo "==> iOS build OK"
