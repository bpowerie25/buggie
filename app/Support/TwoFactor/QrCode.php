<?php

namespace App\Support\TwoFactor;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The square you point a phone at.
 *
 * Rendered on the server and handed over as a data URI rather than drawn in the
 * browser, for one reason: the secret is inside that square. Sending it to a
 * client-side encoder means shipping a QR library to every visitor to pay for one
 * screen, and the widget's whole argument is that bundles are not free.
 */
final class QrCode
{
    /** Black on white, always. A QR reader needs the contrast and the quiet zone,
     * and this page has a dark theme where a transparent background would give it
     * neither. */
    public static function svgDataUri(string $content, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, 2, null, null, Fill::uniformColor(
                new Rgb(255, 255, 255),
                new Rgb(0, 0, 0),
            )),
            new SvgImageBackEnd,
        ));

        // Medium correction: the default, and enough that a phone camera at an angle
        // still reads it without making the square denser than it needs to be.
        $svg = $writer->writeString($content, ecLevel: ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
