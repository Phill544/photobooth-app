import { describe, expect, it } from 'vitest';
import { stripFilename } from './strip-name';

describe('stripFilename', () => {
    it('stamps the strip with the moment it was composed', () => {
        expect(stripFilename('Summer Party', new Date(2026, 8, 13, 14, 32, 8)))
            .toBe('Summer Party_2026-09-13_14-32-08_strip.jpg');
    });

    // Zero-padded and biggest-unit-first, so a downloads folder sorted by name is
    // in the order the strips were shot.
    it('pads every field to a fixed width', () => {
        expect(stripFilename('Summer Party', new Date(2026, 0, 2, 3, 4, 5)))
            .toBe('Summer Party_2026-01-02_03-04-05_strip.jpg');
    });

    // The whole point: one event, one night, two strips, two files. The same name
    // twice is what made a phone ask whether to replace a photo that was not the
    // one already saved.
    it('gives two strips from one event two different names', () => {
        const first = stripFilename('Summer Party', new Date(2026, 8, 13, 21, 5, 0));
        const second = stripFilename('Summer Party', new Date(2026, 8, 13, 21, 6, 30));

        expect(first).not.toBe(second);
    });

    // Saving the same strip twice is the one case that *should* collide: it is the
    // same file, so a browser replacing it is right.
    it('keeps one strip on one name however often it is saved', () => {
        const composed = new Date(2026, 8, 13, 21, 5, 0);

        expect(stripFilename('Summer Party', composed)).toBe(stripFilename('Summer Party', composed));
    });
});
