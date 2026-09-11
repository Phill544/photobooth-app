import { describe, expect, it } from 'vitest';
import { cellRects, stripSize } from './strip-layout';
import { TEMPLATES, templateFor, type StripTemplate } from './templates';

function template(overrides: Partial<StripTemplate> = {}): StripTemplate {
    return {
        cellCount: 3,
        columns: 1,
        cellWidth: 600,
        cellHeight: 450,
        padding: 24,
        footerHeight: 96,
        ...overrides,
    };
}

describe('stripSize', () => {
    it('is one cell wide plus padding on both sides', () => {
        expect(stripSize(template()).width).toBe(24 + 600 + 24);
    });

    it('stacks all cells with padding between, above, and a footer below', () => {
        // padding + 3 * (cell + padding) + footer
        expect(stripSize(template()).height).toBe(24 + 3 * (450 + 24) + 96);
    });

    it('grows with the template cell count, never a hard-coded shot count', () => {
        const two = stripSize(template({ cellCount: 2 })).height;
        const four = stripSize(template({ cellCount: 4 })).height;

        expect(four - two).toBe(2 * (450 + 24));
    });
});

describe('cellRects', () => {
    it('returns one rect per template cell', () => {
        expect(cellRects(template({ cellCount: 2 }))).toHaveLength(2);
        expect(cellRects(template({ cellCount: 4 }))).toHaveLength(4);
    });

    it('positions cells top to bottom with even padding', () => {
        const rects = cellRects(template());

        expect(rects[0]).toEqual({ x: 24, y: 24, width: 600, height: 450 });
        expect(rects[1]).toEqual({ x: 24, y: 24 + 450 + 24, width: 600, height: 450 });
        expect(rects[2]).toEqual({ x: 24, y: 24 + 2 * (450 + 24), width: 600, height: 450 });
    });

    it('keeps every cell above the footer band', () => {
        const t = template({ cellCount: 4 });
        const lastCell = cellRects(t).at(-1)!;

        expect(lastCell.y + lastCell.height).toBe(stripSize(t).height - t.footerHeight - t.padding);
    });
});

describe('multi-column (grid) templates', () => {
    const grid = template({ cellCount: 4, columns: 2 }); // 2x2

    it('is as wide as its columns', () => {
        expect(stripSize(grid).width).toBe(24 + 2 * (600 + 24));
    });

    it('is only as tall as its rows plus the footer', () => {
        // 4 cells in 2 columns = 2 rows
        expect(stripSize(grid).height).toBe(24 + 2 * (450 + 24) + 96);
    });

    it('flows cells left to right, then top to bottom', () => {
        const r = cellRects(grid);
        const col2x = 24 + (600 + 24);
        const row2y = 24 + (450 + 24);

        expect(r[0]).toEqual({ x: 24, y: 24, width: 600, height: 450 });
        expect(r[1]).toEqual({ x: col2x, y: 24, width: 600, height: 450 });
        expect(r[2]).toEqual({ x: 24, y: row2y, width: 600, height: 450 });
        expect(r[3]).toEqual({ x: col2x, y: row2y, width: 600, height: 450 });
    });

    it('rounds a partial last row up (3 cells in 2 columns = 2 rows)', () => {
        const t = template({ cellCount: 3, columns: 2 });

        expect(cellRects(t)).toHaveLength(3);
        expect(stripSize(t).height).toBe(24 + 2 * (450 + 24) + 96);
    });
});

// Everything above works off a local fixture, so it survives a cell resize
// untouched. These are the registry's own numbers — the four sizes a host is
// told, designs artwork against, and downloads a layout guide cut to. They are
// spelled out rather than derived on purpose: a test that recomputed them from
// `base` would agree with any mistake made there.
describe('the sizes the real registry produces', () => {
    it('gives the classic strip the size a host designs against', () => {
        expect(stripSize(templateFor('classic'))).toEqual({ width: 1008, height: 2352 });
    });

    it('gives the tall strip its extra cell', () => {
        expect(stripSize(templateFor('quad'))).toEqual({ width: 1008, height: 3096 });
    });

    it('turns the grid on its side, which is why artwork is not interchangeable', () => {
        expect(stripSize(templateFor('grid'))).toEqual({ width: 1992, height: 1608 });
    });

    it('gives the single shot one cell and a footer', () => {
        expect(stripSize(templateFor('single'))).toEqual({ width: 1008, height: 864 });
    });

    it('draws every guest photo at the size the camera hands over', () => {
        // The camera asks for 1280x720 (camera.ts:27) and grabFrame crops it
        // to the cell's 4:3, which is 960x720. Composing into anything smaller
        // is a downsample the strip pays for and nobody asked for.
        for (const { key } of TEMPLATES) {
            for (const cell of cellRects(templateFor(key))) {
                expect([cell.width, cell.height]).toEqual([960, 720]);
            }
        }
    });
});
