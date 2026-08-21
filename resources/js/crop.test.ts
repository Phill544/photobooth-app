import { describe, expect, it } from 'vitest';
import { centeredCrop } from './crop';

describe('centeredCrop', () => {
    it('crops the sides off a source wider than the target aspect', () => {
        // 16:9 source, 4:3 target: keep full height, trim width to height * 4/3
        const crop = centeredCrop(1920, 1080, 4 / 3);

        expect(crop.height).toBe(1080);
        expect(crop.width).toBe(1440);
        expect(crop.y).toBe(0);
        expect(crop.x).toBe((1920 - 1440) / 2);
    });

    it('crops the top and bottom off a source taller than the target aspect', () => {
        // 3:4 portrait source, 4:3 landscape target
        const crop = centeredCrop(1080, 1440, 4 / 3);

        expect(crop.width).toBe(1080);
        expect(crop.height).toBe(810);
        expect(crop.x).toBe(0);
        expect(crop.y).toBe((1440 - 810) / 2);
    });

    it('keeps the whole frame when the aspect already matches', () => {
        expect(centeredCrop(1200, 900, 4 / 3)).toEqual({ x: 0, y: 0, width: 1200, height: 900 });
    });
});
