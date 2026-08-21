import { cellRects, stripSize } from './strip-layout';
import type { StripTemplate } from './templates';

export function composeStrip(
    shots: HTMLCanvasElement[],
    template: StripTemplate,
    eventName: string,
): HTMLCanvasElement {
    const { width, height } = stripSize(template);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    const ctx = canvas.getContext('2d')!;
    ctx.fillStyle = template.background;
    ctx.fillRect(0, 0, width, height);

    cellRects(template).forEach((cell, index) => {
        ctx.drawImage(shots[index], cell.x, cell.y, cell.width, cell.height);
    });

    ctx.fillStyle = template.textColor;
    ctx.font = `bold ${Math.round(template.footerHeight * 0.4)}px system-ui, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(eventName, width / 2, height - template.footerHeight / 2);

    return canvas;
}
