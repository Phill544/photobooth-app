import { centeredCrop } from './crop';
import { captionLine, footerBand, logoBox } from './strip-footer';
import { cellRects, insetRect, stripSize } from './strip-layout';
import type { StripColours } from './strip-theme';
import type { StripTemplate } from './templates';

// `backgroundImage`, not `background`: that name is already the theme's hex,
// which arrives here through StripColours.
export type Branding = StripColours & {
    caption: string;
    logo?: HTMLImageElement | null;
    // A canvas as well as an image: strip-preview.ts pre-crops the host's pick
    // to the strip once rather than rescaling it on every keystroke.
    backgroundImage?: HTMLImageElement | HTMLCanvasElement | null;
};

// How much of its cell a photo gives back to the mat when the event has a
// background. Without it the artwork survives only in the 24px gutters — 2.4%
// of a classic strip's width — and a host cannot design for a hairline. At 0.08
// the margin is 62px and about a quarter of the strip is theirs. Zero when
// there is no background, so a plain strip composes exactly as it always has.
const MATTED_PHOTO_SHARE = 0.08;

export function composeStrip(
    shots: HTMLCanvasElement[],
    template: StripTemplate,
    branding: Branding,
): HTMLCanvasElement {
    const { width, height } = stripSize(template);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d')!;
    ctx.fillStyle = branding.background;
    ctx.fillRect(0, 0, width, height);

    // Over the theme fill rather than instead of it, so a PNG with transparency
    // tints the host's chosen colour instead of punching a hole to nothing.
    // Cover, never stretch: centeredCrop as the source rect is exactly that,
    // and distorting the artwork is the one thing this feature must not do.
    if (branding.backgroundImage) {
        const art = branding.backgroundImage;
        const crop = centeredCrop(art.width, art.height, width / height);
        ctx.drawImage(art, crop.x, crop.y, crop.width, crop.height, 0, 0, width, height);
    }

    const photoShare = branding.backgroundImage ? MATTED_PHOTO_SHARE : 0;
    cellRects(template).forEach((cell, index) => {
        const photo = insetRect(cell, photoShare);
        ctx.drawImage(shots[index], photo.x, photo.y, photo.width, photo.height);
    });

    // The footer goes on last and is never covered: a background can change the
    // ground the caption sits on, but it can't take the strip's one line of text.
    const band = footerBand({ width, height }, template);
    if (branding.logo) {
        const box = logoBox(branding.logo, band);
        ctx.drawImage(branding.logo, box.x, box.y, box.width, box.height);
    } else {
        // The context is the only thing that knows how wide the text really is.
        const line = captionLine(branding.caption, band, (text, font) => {
            ctx.font = font;
            return ctx.measureText(text).width;
        });
        ctx.fillStyle = branding.textColor;
        ctx.font = line.font;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(line.text, band.width / 2, band.centerY);
    }

    return canvas;
}
