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

// How the caller measures text: strip-compose.ts hands over its own canvas
// context, which is the only thing that knows how wide a glyph really is.
export type Measure = (text: string, font: string) => number;

const LOGO_HEIGHT_SHARE = 0.62; // of the band
const LOGO_WIDTH_SHARE = 0.7; // of the strip
const CAPTION_HEIGHT_SHARE = 0.4; // of the band
const CAPTION_FLOOR_SHARE = 0.25; // of the band — smaller than this and we ellipsise instead
const ELLIPSIS = '…';

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

// The caption is the one thing on the strip a host typed, and it defaults to
// the event name — so it is routinely longer than the strip is wide. Fit it:
// step the size down to a floor, and only then cut it short. It never spills
// past the photos above it, because a clipped caption reads as a broken strip.
export function captionLine(caption: string, band: FooterBand, measure: Measure): { text: string; font: string } {
    const font = (size: number) => `bold ${size}px ${CAPTION_FONT_STACK}`;
    const floor = Math.round(band.height * CAPTION_FLOOR_SHARE);

    for (let size = Math.round(band.height * CAPTION_HEIGHT_SHARE); size >= floor; size--) {
        if (measure(caption, font(size)) <= band.innerWidth) return { text: caption, font: font(size) };
    }

    // Still too long at the floor — a 60-character caption is — so trim it back
    // until the ellipsis fits. Trim whole characters, never code units: event
    // names really do carry emoji, and half of a surrogate pair inks as a tofu
    // box exactly where the ellipsis should be. Don't strand a space either.
    // Built here rather than at module scope: capture.ts imports this file
    // transitively, so a throw at import time would kill every control in the
    // booth before its own error handlers exist, with nothing on screen to say
    // so. This branch only runs for a caption too long to shrink into the mat.
    const shown = (parts: string[]) => parts.join('').trimEnd() + ELLIPSIS;
    const parts = [...new Intl.Segmenter().segment(caption)].map((part) => part.segment);
    while (parts.length && measure(shown(parts), font(floor)) > band.innerWidth) {
        parts.pop();
    }

    return { text: shown(parts), font: font(floor) };
}
