import { describe, expect, it } from 'vitest';
import { buildPhotoForm } from './upload';

describe('buildPhotoForm', () => {
    it('packs the photo and its metadata into multipart fields', () => {
        const photo = new Blob(['fake-jpeg-bytes'], { type: 'image/jpeg' });

        const form = buildPhotoForm(photo, {
            kind: 'original',
            group: 'aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10',
            slot: 2,
        });

        expect(form.get('kind')).toBe('original');
        expect(form.get('group')).toBe('aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10');
        expect(form.get('slot')).toBe('2');
        expect((form.get('photo') as File).name).toBe('original-2.jpg');
    });

    it('names strip uploads by kind and slot', () => {
        const photo = new Blob([''], { type: 'image/jpeg' });

        const form = buildPhotoForm(photo, { kind: 'strip', group: 'x', slot: 0 });

        expect((form.get('photo') as File).name).toBe('strip-0.jpg');
    });
});
