#!/usr/bin/env bash
# Runs the BuggieCore tests.
#
# With Xcode installed, `swift test` works on its own. With only the Command Line
# Tools, swift-testing ships in the toolchain but is not on the search path, so the
# framework and its interop library have to be pointed at explicitly.
set -euo pipefail

cd "$(dirname "$0")"

developer_dir="$(xcode-select -p)"

if [[ "$developer_dir" == *"CommandLineTools"* ]]; then
    frameworks="$developer_dir/Library/Developer/Frameworks"
    interop="$developer_dir/Library/Developer/usr/lib"

    echo "Command Line Tools only: building for macOS, without the UIKit layer."

    exec swift test --disable-xctest \
        -Xswiftc -F -Xswiftc "$frameworks" \
        -Xlinker -F -Xlinker "$frameworks" \
        -Xlinker -rpath -Xlinker "$frameworks" \
        -Xlinker -rpath -Xlinker "$interop" \
        "$@"
fi

exec swift test "$@"
