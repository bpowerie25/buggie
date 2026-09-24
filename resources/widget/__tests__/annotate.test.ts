import { describe, expect, it } from 'vitest';
import { toCanvasPoint } from '../screenshot';

/**
 * Where a drag lands on the screenshot: a point on the image as shown, scaled to the
 * image's own pixels. The browser half of it — clientX against the screen rectangle —
 * was checked in Chrome with and without CSS zoom on the page; this pins the rest.
 */
describe('toCanvasPoint', () => {
    it('maps a point on the shown image to the same point in its pixels', () => {
        // A 1512×813 capture shown at 1149×618.
        expect(toCanvasPoint(574.5, 309, 1149, 618, 1512, 813)).toEqual({ x: 756, y: 406.5 });
    });

    it('keeps the corners at the corners', () => {
        expect(toCanvasPoint(0, 0, 400, 200, 1600, 800)).toEqual({ x: 0, y: 0 });
        expect(toCanvasPoint(400, 200, 400, 200, 1600, 800)).toEqual({ x: 1600, y: 800 });
    });

    it('clamps a drag that leaves the image to its edge', () => {
        expect(toCanvasPoint(-30, 250, 400, 200, 1600, 800)).toEqual({ x: 0, y: 800 });
    });

    it('does not divide by zero before the image has a size', () => {
        expect(toCanvasPoint(10, 10, 0, 0, 1600, 800)).toEqual({ x: 0, y: 0 });
    });
});
