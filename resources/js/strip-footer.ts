// The strip's footer band — the mat under the cells — and what is typeset into
// it: a logo box, or a line of caption text. Pure geometry and typesetting, so
// it is tested without a canvas; strip-compose.ts does the drawing.

import type { StripTemplate } from './templates';

export type Size = { width: number; height: number };
export type Rect = { x: number; y: number; width: number; height: number };

// The band sits at the foot of the strip and spans its full width; `innerWidth`
// is that minus the mat, which makes it exactly as wide as the photos above it.
// Everything in the footer is placed against the band's centre line.
export type FooterBand = { width: number; innerWidth: number; height: number; centerY: number };

export const CAPTION_FONT_STACK = 'system-ui, sans-serif';

const LOGO_HEIGHT_SHARE = 0.62; // of the band
const LOGO_WIDTH_SHARE = 0.7; // of the strip
const CAPTION_HEIGHT_SHARE = 0.4; // of the band

export function footerBand(strip: Size, template: StripTemplate): FooterBand {
    return {
        width: strip.width,
        innerWidth: strip.width - 2 * template.padding,
        height: template.footerHeight,
        centerY: strip.height - template.footerHeight / 2,
    };
}

// A logo takes the footer instead of the caption text — one or the other. It is
// scaled to the band (up as well as down: a small logo fills it rather than
// floating in the middle of an empty mat) and centred.
export function logoBox(logo: Size, band: FooterBand): Rect {
    const scale = Math.min(
        (band.height * LOGO_HEIGHT_SHARE) / logo.height,
        (band.width * LOGO_WIDTH_SHARE) / logo.width,
    );
    const width = logo.width * scale;
    const height = logo.height * scale;

    return { x: (band.width - width) / 2, y: band.centerY - height / 2, width, height };
}

export function captionLine(caption: string, band: FooterBand): { text: string; font: string } {
    const size = Math.round(band.height * CAPTION_HEIGHT_SHARE);

    return { text: caption, font: `bold ${size}px ${CAPTION_FONT_STACK}` };
}
