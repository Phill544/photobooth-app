import { describe, expect, it } from 'vitest';
import { captionLine, footerBand, logoBox } from './strip-footer';
import type { StripTemplate } from './templates';

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

// A classic single-column strip: 648 x 1542, with a 96px footer band at its foot.
const strip = { width: 648, height: 1542 };
const band = footerBand(strip, template());


describe('footerBand', () => {
    it('is as wide as the strip, footer-tall, and centred on the band', () => {
        expect(band.width).toBe(648);
        expect(band.height).toBe(96);
        expect(band.centerY).toBe(1542 - 48);
    });

    it('is as wide inside the mat as the photos above it', () => {
        expect(band.innerWidth).toBe(600);
        expect(footerBand({ width: 1272, height: 1092 }, template({ columns: 2 })).innerWidth).toBe(1224);
    });

    it('follows the strip height rather than a fixed offset', () => {
        expect(footerBand({ width: 1272, height: 1092 }, template()).centerY).toBe(1092 - 48);
    });
});

describe('logoBox', () => {
    it('centres the logo on the band', () => {
        const box = logoBox({ width: 200, height: 100 }, band);

        expect(box.x + box.width / 2).toBeCloseTo(strip.width / 2);
        expect(box.y + box.height / 2).toBeCloseTo(band.centerY);
    });

    it('holds a wide logo inside 70% of the strip', () => {
        expect(logoBox({ width: 4000, height: 100 }, band).width).toBeCloseTo(648 * 0.7);
    });

    it('holds a tall logo inside 62% of the band', () => {
        expect(logoBox({ width: 100, height: 4000 }, band).height).toBeCloseTo(96 * 0.62);
    });

    it('never distorts the logo', () => {
        const box = logoBox({ width: 300, height: 150 }, band);

        expect(box.width / box.height).toBeCloseTo(2);
    });

    it('scales a small logo up, so the footer is never half empty', () => {
        expect(logoBox({ width: 20, height: 10 }, band).height).toBeCloseTo(96 * 0.62);
    });
});

describe('captionLine', () => {
    it('typesets bold at 40% of the band height', () => {
        expect(captionLine('Sam & Ali', band).font).toBe('bold 38px system-ui, sans-serif');
    });

    it('sizes from the band, so a taller footer prints a bigger caption', () => {
        const tall = footerBand(strip, template({ footerHeight: 200 }));

        expect(captionLine('Sam & Ali', tall).font).toBe('bold 80px system-ui, sans-serif');
    });

    it('prints the caption it was given', () => {
        expect(captionLine('Sam & Ali', band).text).toBe('Sam & Ali');
    });
});
