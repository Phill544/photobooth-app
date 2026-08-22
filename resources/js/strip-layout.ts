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
