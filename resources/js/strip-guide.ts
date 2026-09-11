// The layout guide: where a host's artwork meets the photos.
//
// Pure geometry, so it is unit-tested and — more to the point — *derived*. A
// checked-in PNG per template would be a fifth mirror of numbers that have
// already moved once (600x450 to 960x720), and the day it drifted, every host
// designing against it would find out at the event. Everything here reads the
// same registry composeStrip does, so a guide cannot describe a strip the booth
// does not draw. strip-preview.ts paints these marks onto a canvas.

import { footerBand, footerSafeRect } from './strip-footer';
import { cellRects, insetRect, MATTED_PHOTO_SHARE, stripSize, stripSizeLabel, type Rect } from './strip-layout';
import type { StripTemplate } from './templates';

export { MATTED_PHOTO_SHARE };

export type GuideMarks = {
    size: { width: number; height: number };
    // Where each photo actually lands, in slot order.
    windows: Rect[];
    // Everything below the last photo: the padding above the band plus the band
    // itself. The band alone starts one padding lower, so a host who marks that
    // instead sets their monogram 24px off centre.
    mat: Rect;
    // What the caption or the logo will print over.
    textBox: Rect;
};

export function guideMarks(template: StripTemplate): GuideMarks {
    const size = stripSize(template);
    const matHeight = template.padding + template.footerHeight;

    return {
        size,
        // Insets, not raw cells: the guide only exists for events with a
        // background, and a background is exactly what moves the photos in.
        windows: cellRects(template).map((cell) => insetRect(cell, MATTED_PHOTO_SHARE)),
        mat: { x: 0, y: size.height - matHeight, width: size.width, height: matHeight },
        textBox: footerSafeRect(footerBand(size, template)),
    };
}

// Underscores between fields, so a folder of these sorts sensibly and the size
// is readable at a glance in a downloads list. No event name: a host downloads
// this from /new, where there is no event yet.
export function guideFilename(templateKey: string, size: { width: number; height: number }): string {
    return `quikbooth_${templateKey}_${size.width}x${size.height}_guide.png`;
}

// The downloadable layout guide. Everything is drawn inside the canvas and the
// canvas is exactly the strip, because its highest-value use is "make my Canva
// page this size" — anything outside the bounds would break that. The ground
// stays transparent so it also drops in as a top reference layer.
//
// Every label sits inside a photo window or inside the caption zone, which are
// the only regions a host is *not* designing into: the mat is the thing they
// came here to fill, so writing across it would be covering the answer with the
// question. The 24px gutters cannot hold legible type anyway.
export function drawGuide(template: StripTemplate): HTMLCanvasElement {
    const { size, windows, mat, textBox } = guideMarks(template);
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const ctx = canvas.getContext('2d')!;

    const label = (text: string, x: number, y: number, px: number) => {
        ctx.font = `600 ${px}px system-ui, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        // Struck in white then filled dark, so a label stays legible whether the
        // host drops this over black artwork or white.
        ctx.lineWidth = Math.max(3, px * 0.22);
        ctx.strokeStyle = '#FFFFFF';
        ctx.strokeText(text, x, y);
        ctx.fillStyle = '#111111';
        ctx.fillText(text, x, y);
    };

    // The photo windows, in the same grey and numeral the preview uses, so the
    // download looks like the strip the host was just looking at.
    windows.forEach((window, index) => {
        ctx.fillStyle = '#8f8a82';
        ctx.fillRect(window.x, window.y, window.width, window.height);
        label(String(index + 1), window.x + window.width / 2, window.y + window.height / 2,
            Math.round(window.height * 0.26));
    });

    // The two things a host needs to know, in the first window: how big to work,
    // and that these grey boxes are not theirs. Placed at 15% and 85% of its
    // height so neither meets the numeral between them.
    const first = windows[0];
    const centreX = first.x + first.width / 2;
    label(stripSizeLabel(template), centreX, first.y + first.height * 0.15,
        Math.round(first.height * 0.075));
    label('YOUR PHOTOS COVER THESE', centreX, first.y + first.height * 0.85,
        Math.round(first.height * 0.055));

    // The mat a host is designing into, and the box the app will print over the
    // top of it.
    ctx.setLineDash([18, 12]);
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#111111';
    ctx.strokeRect(mat.x + 1.5, mat.y + 1.5, mat.width - 3, mat.height - 3);
    ctx.strokeRect(textBox.x, textBox.y, textBox.width, textBox.height);
    ctx.setLineDash([]);
    // Inside the box it describes, centred on the line the caption really sits
    // on — so a host can see the words land where the app will put them.
    label('your caption or logo prints here', textBox.x + textBox.width / 2,
        textBox.y + textBox.height / 2, Math.round(textBox.height * 0.42));

    // A two-tone outer rule, so the bounds read on dark and light artwork.
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#111111';
    ctx.strokeRect(1.5, 1.5, size.width - 3, size.height - 3);
    ctx.strokeStyle = '#FFFFFF';
    ctx.strokeRect(4.5, 4.5, size.width - 9, size.height - 9);

    return canvas;
}
