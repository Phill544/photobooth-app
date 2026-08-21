import type { StripTemplate } from './templates';

export type Rect = { x: number; y: number; width: number; height: number };

export function stripSize(template: StripTemplate): { width: number; height: number } {
    return {
        width: template.padding * 2 + template.cellWidth,
        height: template.padding
            + template.cellCount * (template.cellHeight + template.padding)
            + template.footerHeight,
    };
}

export function cellRects(template: StripTemplate): Rect[] {
    return Array.from({ length: template.cellCount }, (_, index) => ({
        x: template.padding,
        y: template.padding + index * (template.cellHeight + template.padding),
        width: template.cellWidth,
        height: template.cellHeight,
    }));
}
