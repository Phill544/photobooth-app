import { describe, expect, it } from 'vitest';
import { applyColorMatrix, FILTERS, filterFor } from './filters';

describe('the filter registry', () => {
    it('offers the six looks, keyed', () => {
        expect(FILTERS.map((f) => f.key)).toEqual(['none', 'mono', 'warm', 'cool', 'vivid', 'film']);
    });

    it('derives the CSS string from the op list', () => {
        expect(filterFor('mono').css).toBe('grayscale(1) contrast(1.08) brightness(1.05)');
        expect(filterFor('cool').css).toContain('hue-rotate(-10deg)');
        expect(filterFor('none').css).toBe('none');
    });

    it('falls back to None for an unknown key', () => {
        expect(filterFor('kaleidoscope')).toBe(filterFor('none'));
    });

    it('gives every filter a 4x5 matrix', () => {
        for (const f of FILTERS) expect(f.matrix).toHaveLength(20);
    });
});

function pixel(r: number, g: number, b: number): Uint8ClampedArray {
    return new Uint8ClampedArray([r, g, b, 255]);
}

describe('applyColorMatrix', () => {
    it('leaves pixels untouched for None (identity)', () => {
        const p = pixel(12, 34, 56);
        applyColorMatrix(p, filterFor('none').matrix);
        expect([...p]).toEqual([12, 34, 56, 255]);
    });

    it('collapses colour to grey for Noir', () => {
        const p = pixel(255, 0, 0);
        applyColorMatrix(p, filterFor('mono').matrix);
        // grayscale makes the three channels equal; contrast/brightness keep them equal.
        expect(p[0]).toBe(p[1]);
        expect(p[1]).toBe(p[2]);
        expect(p[3]).toBe(255);
        expect(p[0]).toBeGreaterThan(30);
        expect(p[0]).toBeLessThan(80);
    });

    it('keeps a warm filter warmer in red than blue', () => {
        const grey = pixel(128, 128, 128);
        applyColorMatrix(grey, filterFor('warm').matrix);
        expect(grey[0]).toBeGreaterThan(grey[2]); // golden cast: red channel lifted above blue
    });
});
