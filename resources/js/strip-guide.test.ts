import { describe, expect, it } from 'vitest';
import { guideFilename, guideMarks, MATTED_PHOTO_SHARE } from './strip-guide';
import { cellRects, insetRect, stripSize } from './strip-layout';
import { footerBand } from './strip-footer';
import { templateFor, type StripTemplate } from './templates';

function template(overrides: Partial<StripTemplate> = {}): StripTemplate {
    return { cellCount: 3, columns: 1, cellWidth: 960, cellHeight: 720, padding: 24, footerHeight: 96, ...overrides };
}

describe('guideMarks', () => {
    it('is exactly the strip it guides, for a single column and a grid', () => {
        for (const t of [template(), template({ cellCount: 4, columns: 2 })]) {
            expect(guideMarks(t).size).toEqual(stripSize(t));
        }
    });

    it('marks every cell the template has, never a hard-coded shot count', () => {
        expect(guideMarks(template({ cellCount: 7, columns: 3 })).windows).toHaveLength(7);
    });

    // The guide's whole job. A host lays their artwork out against these boxes,
    // so a window drawn where the photo used to land — before a background
    // pulled it in — is off by 38.4 x 28.8 px on every strip of the night.
    it('puts every window where a photo really lands on a strip that has artwork', () => {
        const t = template();

        expect(guideMarks(t).windows).toEqual(cellRects(t).map((cell) => insetRect(cell, MATTED_PHOTO_SHARE)));
    });

    it('marks the whole region under the last cell, not just the footer band', () => {
        // The mat under the photos is padding + footerHeight; the band object
        // describes only the footer part, starting one padding lower. Conflate
        // the two and a host sets their monogram 24px off centre.
        const t = template();

        expect(guideMarks(t).mat.height).toBe(t.padding + t.footerHeight);
        expect(guideMarks(t).mat.y).toBe(stripSize(t).height - t.padding - t.footerHeight);
    });

    it('reserves the box the caption and the logo share', () => {
        const t = template();
        const band = footerBand(stripSize(t), t);

        expect(guideMarks(t).textBox.width).toBe(band.innerWidth);
        expect(guideMarks(t).textBox.y + guideMarks(t).textBox.height / 2).toBeCloseTo(band.centerY);
    });

    it('follows a cell resize instead of describing one', () => {
        const small = guideMarks(template({ cellWidth: 600, cellHeight: 450 }));

        expect(small.size).toEqual({ width: 648, height: 1542 });
        expect(small.windows[1].y).toBeCloseTo(24 + 474 + 450 * MATTED_PHOTO_SHARE / 2);
    });

    it('keeps every mark inside the strip', () => {
        for (const key of ['classic', 'quad', 'grid', 'single']) {
            const t = templateFor(key);
            const { size, windows, mat, textBox } = guideMarks(t);

            for (const rect of [...windows, mat, textBox]) {
                expect(rect.x).toBeGreaterThanOrEqual(0);
                expect(rect.y).toBeGreaterThanOrEqual(0);
                expect(rect.x + rect.width).toBeLessThanOrEqual(size.width);
                expect(rect.y + rect.height).toBeLessThanOrEqual(size.height);
            }
        }
    });
});

describe('guideFilename', () => {
    it('names the file after the layout and the size it is cut to', () => {
        expect(guideFilename('classic', { width: 1008, height: 2352 }))
            .toBe('quikbooth_classic_1008x2352_guide.png');
    });

    it('stays a plain-sorting name whatever the layout', () => {
        expect(guideFilename('grid', { width: 1992, height: 1608 }))
            .toBe('quikbooth_grid_1992x1608_guide.png');
    });
});
