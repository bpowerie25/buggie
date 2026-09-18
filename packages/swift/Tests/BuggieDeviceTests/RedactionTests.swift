#if canImport(UIKit)
import Foundation
import Testing
import UIKit
@testable import Buggie

/// The claim this SDK cannot afford to get wrong, checked against actual pixels.
///
/// Reasoning about `drawHierarchy` is how the bug got in: the code looked correct,
/// hid the views before rendering, and still produced an image with the password in
/// it, because the render read a buffer composited before the hiding. Nothing short
/// of inspecting the output would have caught that.
@MainActor
@Suite("Redaction, on a device")
struct RedactionTests {
    /// A red view inside a white one. Red is the secret.
    private func hierarchy(redacted: Bool) -> UIView {
        let container = UIView(frame: CGRect(x: 0, y: 0, width: 80, height: 80))
        container.backgroundColor = .white

        let secret = UIView(frame: CGRect(x: 20, y: 20, width: 40, height: 40))
        secret.backgroundColor = .red
        secret.buggieRedacted = redacted
        container.addSubview(secret)

        return container
    }

    /// Whether the captured image contains any strongly red pixel.
    private func containsRed(_ data: Data) throws -> Bool {
        let image = try #require(UIImage(data: data)?.cgImage)
        let width = image.width
        let height = image.height

        var pixels = [UInt8](repeating: 0, count: width * height * 4)
        let context = try #require(CGContext(
            data: &pixels,
            width: width,
            height: height,
            bitsPerComponent: 8,
            bytesPerRow: width * 4,
            space: CGColorSpaceCreateDeviceRGB(),
            bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue
        ))

        context.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))

        return stride(from: 0, to: pixels.count, by: 4).contains { i in
            // Generous thresholds: JPEG is lossy and the edges will be muddied.
            pixels[i] > 180 && pixels[i + 1] < 90 && pixels[i + 2] < 90
        }
    }

    @Test("A marked view does not appear in the capture")
    func redactedViewIsAbsent() throws {
        let data = try #require(Buggie.shared.capture(hierarchy(redacted: true)))

        #expect(try containsRed(data) == false)
    }

    @Test("The same view does appear when it is not marked")
    func unredactedViewIsPresent() throws {
        // The control, and it is not a formality: it is the only test here that
        // catches the bug this file exists for.
        //
        // Reverting `captureScreen` to `afterScreenUpdates: false` was tried. The test
        // above — the one that sounds like the important one — *passed*, because
        // nothing was drawn at all and a blank image contains no red. Only this test
        // failed. A negative assertion is satisfied by an empty result, so on its own
        // it proves nothing about redaction; it proves the capture produced no red,
        // which is also true of a capture that produced nothing.
        //
        // Delete this and the suite still passes while the screenshot is broken.
        let data = try #require(Buggie.shared.capture(hierarchy(redacted: false)))

        #expect(try containsRed(data) == true)
    }

    @Test("A secure text field is redacted without anyone marking it")
    func secureFieldsAreFoundAutomatically() {
        let container = UIView(frame: CGRect(x: 0, y: 0, width: 80, height: 80))
        let field = UITextField(frame: container.bounds)
        field.isSecureTextEntry = true
        container.addSubview(field)

        #expect(container.buggieRedactedSubviews().contains(field))
    }

    @Test("Marking is found at any depth, and unmarked views are left alone")
    func nesting() {
        let root = UIView()
        let middle = UIView()
        let deep = UIView()
        deep.buggieRedacted = true
        middle.addSubview(deep)
        root.addSubview(middle)

        let found = root.buggieRedactedSubviews()

        #expect(found == [deep])
    }

    @Test("The capture is restored afterwards, so the app is not left broken")
    func viewsAreUnhiddenAfterwards() throws {
        let container = hierarchy(redacted: true)
        let secret = try #require(container.subviews.first)

        _ = Buggie.shared.capture(container)

        #expect(secret.isHidden == false)
    }
}
#endif
