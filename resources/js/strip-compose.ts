import { captionLine, footerBand, logoBox } from './strip-footer';
import { cellRects, stripSize } from './strip-layout';
import type { StripColours } from './strip-theme';
import type { StripTemplate } from './templates';

export type Branding = StripColours & { caption: string; logo?: HTMLImageElement | null };

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

    cellRects(template).forEach((cell, index) => {
        ctx.drawImage(shots[index], cell.x, cell.y, cell.width, cell.height);
    });

    const band = footerBand({ width, height }, template);
    if (branding.logo) {
        const box = logoBox(branding.logo, band);
        ctx.drawImage(branding.logo, box.x, box.y, box.width, box.height);
    } else {
        const line = captionLine(branding.caption, band);
        ctx.fillStyle = branding.textColor;
        ctx.font = line.font;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(line.text, band.width / 2, band.centerY);
    }

    return canvas;
}
