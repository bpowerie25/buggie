// swift-tools-version: 5.9
import PackageDescription

let package = Package(
    name: "Buggie",
    platforms: [
        .iOS(.v15),
        // macOS is listed so the core can be built and tested without Xcode. The
        // UIKit layer compiles only where UIKit exists.
        .macOS(.v12),
    ],
    products: [
        .library(name: "Buggie", targets: ["Buggie"]),
        .library(name: "BuggieCore", targets: ["BuggieCore"]),
    ],
    targets: [
        // Everything that can be reasoned about without a screen: building the
        // payload, redaction, breadcrumb buffers, transport.
        .target(name: "BuggieCore"),

        // The part that needs a device: screen capture and what the device knows
        // about itself. Kept as thin as it can be, because UIKit code cannot be
        // unit tested here and untestable code should be small enough to read.
        .target(name: "Buggie", dependencies: ["BuggieCore"]),

        .testTarget(name: "BuggieCoreTests", dependencies: ["BuggieCore"]),
    ]
)
