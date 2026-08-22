import { cellRects, stripSize } from './strip-layout';
import type { StripColours } from './strip-theme';
import type { StripTemplate } from './templates';

export type Branding = StripColours & { caption: string };

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

    ctx.fillStyle = branding.textColor;
    ctx.font = `bold ${Math.round(template.footerHeight * 0.4)}px system-ui, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(branding.caption, width / 2, height - template.footerHeight / 2);

    return canvas;
}
