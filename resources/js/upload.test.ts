import { describe, expect, it } from 'vitest';
import { buildPhotoForm, isTerminal, uploadFailure } from './upload';

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

describe('uploadFailure', () => {
    it('reads a closed booth off a 410', () => {
        const failure = uploadFailure(410, null);

        expect(failure.kind).toBe('closed');
        expect(failure.retryAfterMs).toBeNull();
    });

    it('reads a rejected file off a 422', () => {
        expect(uploadFailure(422, null).kind).toBe('rejected');
    });

    it('reads a throttled event off a 429 and honours Retry-After seconds', () => {
        const failure = uploadFailure(429, '30');

        expect(failure.kind).toBe('throttled');
        expect(failure.retryAfterMs).toBe(30000);
    });

    it('takes a 429 with no usable Retry-After as throttled with no stated wait', () => {
        expect(uploadFailure(429, null).retryAfterMs).toBeNull();
        expect(uploadFailure(429, 'Wed, 21 Oct 2026 07:28:00 GMT').retryAfterMs).toBeNull();
    });

    it('treats anything else as a network failure worth another try', () => {
        expect(uploadFailure(500, null).kind).toBe('network');
        expect(uploadFailure(0, null).kind).toBe('network');
    });
});

describe('isTerminal', () => {
    it('is true for the failures a retry can never fix', () => {
        expect(isTerminal(uploadFailure(410, null))).toBe(true);
        expect(isTerminal(uploadFailure(422, null))).toBe(true);
    });

    it('is false for the failures that clear on their own', () => {
        expect(isTerminal(uploadFailure(429, '5'))).toBe(false);
        expect(isTerminal(uploadFailure(503, null))).toBe(false);
    });
});
