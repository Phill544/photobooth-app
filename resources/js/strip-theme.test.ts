import { describe, expect, it } from 'vitest';
import { STRIP_THEMES, stripTheme } from './strip-theme';

describe('stripTheme', () => {
    it('returns the colours for a known theme', () => {
        expect(stripTheme('forest')).toEqual({ background: '#1E3A2F', textColor: '#F0EAD6' });
    });

    it('falls back to midnight for an unknown theme', () => {
        expect(stripTheme('chartreuse')).toEqual(stripTheme('midnight'));
    });

    it('every listed theme resolves to a background and text colour', () => {
        for (const { key } of STRIP_THEMES) {
            expect(stripTheme(key).background).toMatch(/^#[0-9A-F]{6}$/i);
            expect(stripTheme(key).textColor).toMatch(/^#[0-9A-F]{6}$/i);
        }
    });
});
