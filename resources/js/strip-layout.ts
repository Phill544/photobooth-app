import type { StripTemplate } from './templates';

export type Rect = { x: number; y: number; width: number; height: number };

function rowCount(template: StripTemplate): number {
    return Math.ceil(template.cellCount / template.columns);
}

export function stripSize(template: StripTemplate): { width: number; height: number } {
    return {
        width: template.padding + template.columns * (template.cellWidth + template.padding),
        height: template.padding
            + rowCount(template) * (template.cellHeight + template.padding)
            + template.footerHeight,
    };
}

// Shrink a rect about its own centre by a share of itself. Used to pull each
// photo in from its cell when the event has a background: the cells cover
// 79-87% of a strip, so without this a host's artwork survives only in the 24px
// gutters, which is a hairline rather than a design. A share rather than a
// pixel margin, so it costs nothing when the cells are next resized and the
// photo keeps the cell's aspect — what a guest framed is what lands.
export function insetRect(rect: Rect, share: number): Rect {
    const width = rect.width * (1 - share);
    const height = rect.height * (1 - share);

    return {
        x: rect.x + (rect.width - width) / 2,
        y: rect.y + (rect.height - height) / 2,
        width,
        height,
    };
}

export function cellRects(template: StripTemplate): Rect[] {
    return Array.from({ length: template.cellCount }, (_, index) => {
        const col = index % template.columns;
        const row = Math.floor(index / template.columns);
        return {
            x: template.padding + col * (template.cellWidth + template.padding),
            y: template.padding + row * (template.cellHeight + template.padding),
            width: template.cellWidth,
            height: template.cellHeight,
        };
    });
}
