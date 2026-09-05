import { describe, expect, it, vi } from 'vitest';
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

// A stand-in for the canvas: every glyph is half the font size wide. At the
// full 38px that puts 31 characters across a 600px strip, which is what the
// real thing measures too.
const measure = (text: string, font: string) => text.length * Number(font.match(/(\d+)px/)![1]) * 0.5;

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

// capture.ts imports this module transitively, through strip-compose. Anything
// this file does at import time therefore runs before the booth's own error
// handlers are registered, and a throw there kills every control on the page
// with nothing on screen to say so. So it must do nothing at import time.
describe('importing the module', () => {
    it('constructs nothing, so the booth cannot die on a module it barely uses', async () => {
        const real = Intl.Segmenter;
        // A browser without it, or with an ICU build whose constructor throws.
        (Intl as { Segmenter?: unknown }).Segmenter = undefined;
        vi.resetModules();

        try {
            const fresh = await import('./strip-footer');

            expect(fresh.captionLine('Sam & Ali', band, measure).text).toBe('Sam & Ali');
        } finally {
            (Intl as { Segmenter?: unknown }).Segmenter = real;
            vi.resetModules();
        }
    });
});

describe('captionLine', () => {
    it('typesets bold at 40% of the band height', () => {
        expect(captionLine('Sam & Ali', band, measure).font).toBe('bold 38px system-ui, sans-serif');
    });

    it('sizes from the band, so a taller footer prints a bigger caption', () => {
        const tall = footerBand(strip, template({ footerHeight: 200 }));

        expect(captionLine('Sam & Ali', tall, measure).font).toBe('bold 80px system-ui, sans-serif');
    });

    it('prints a caption that already fits exactly as it was given', () => {
        expect(captionLine('Sam & Ali', band, measure).text).toBe('Sam & Ali');
    });

    it('shrinks a long caption until it fits rather than letting it run off the strip', () => {
        const long = 'M'.repeat(40);
        const line = captionLine(long, band, measure);

        expect(line.text).toBe(long);
        expect(line.font).toBe('bold 30px system-ui, sans-serif');
        expect(measure(line.text, line.font)).toBeLessThanOrEqual(band.innerWidth);
    });

    it('has more room on a wider strip, so the same caption stays full size', () => {
        const grid = footerBand({ width: 1272, height: 1092 }, template({ columns: 2 }));

        expect(captionLine('M'.repeat(40), grid, measure).font).toBe('bold 38px system-ui, sans-serif');
    });

    it('stops shrinking at a floor — a caption is text, not a watermark', () => {
        expect(captionLine('M'.repeat(200), band, measure).font).toBe('bold 24px system-ui, sans-serif');
    });

    it('ellipsises what still will not fit at the floor', () => {
        const line = captionLine('M'.repeat(60), band, measure);

        expect(line.text).toBe('M'.repeat(49) + '…');
        expect(measure(line.text, line.font)).toBeLessThanOrEqual(band.innerWidth);
    });

    it('cuts whole characters, so an emoji never prints as half of itself', () => {
        const line = captionLine('M'.repeat(48) + '\u{1F389}\u{1F942}', band, measure);

        expect(line.text).toBe('M'.repeat(48) + '…');
        expect(line.text).not.toMatch(/[\uD800-\uDBFF](?![\uDC00-\uDFFF])/); // a lone high surrogate inks as tofu
    });

    it('keeps a multi-part emoji whole rather than stranding a piece of it', () => {
        const family = '\u{1F468}‍\u{1F469}‍\u{1F467}‍\u{1F466}';
        const line = captionLine('M'.repeat(45) + family, band, measure);

        expect(line.text).toBe('M'.repeat(45) + '…');
    });

    it('never leaves a space stranded before the ellipsis', () => {
        const line = captionLine('M'.repeat(48) + '   ' + 'M'.repeat(20), band, measure);

        expect(line.text).toBe('M'.repeat(48) + '…');
    });

    it('draws nothing for an empty caption', () => {
        expect(captionLine('', band, measure).text).toBe('');
    });
});
