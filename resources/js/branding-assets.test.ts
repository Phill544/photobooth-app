import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadBrandingImage, type LoadableImage } from './branding-assets';

// A stand-in for `new Image()`: the booth passes the real constructor, the tests
// pass this and fire the handlers by hand. Nothing here needs a DOM.
function fakeImages() {
    const made: LoadableImage[] = [];

    return {
        made,
        create: () => {
            const image = { src: '', onload: null, onerror: null } as LoadableImage;
            made.push(image);
            return image;
        },
    };
}

afterEach(() => vi.useRealTimers());

describe('loadBrandingImage', () => {
    it('resolves nothing when the event has no image, without touching the network', async () => {
        const images = fakeImages();

        await expect(loadBrandingImage(undefined, images.create, 3000)).resolves.toBeNull();
        await expect(loadBrandingImage('', images.create, 3000)).resolves.toBeNull();
        expect(images.made).toHaveLength(0);
    });

    it('resolves the image once it has loaded', async () => {
        const images = fakeImages();
        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000);

        expect(images.made[0].src).toBe('/e/PARTY2/logo');
        images.made[0].onload!();

        await expect(loading).resolves.toBe(images.made[0]);
    });

    it('resolves nothing when the image fails, rather than hanging the booth', async () => {
        const images = fakeImages();
        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000);

        images.made[0].onerror!();

        await expect(loading).resolves.toBeNull();
    });

    it('gives up at the deadline rather than holding the review screen shut', async () => {
        vi.useFakeTimers();
        const images = fakeImages();
        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000);

        // Venue wifi that accepts the connection and then stalls fires neither
        // handler, so there is nothing to wait for and no error to react to.
        vi.advanceTimersByTime(3000);

        await expect(loading).resolves.toBeNull();
    });

    it('settles once, even when a late load follows the deadline', async () => {
        vi.useFakeTimers();
        const images = fakeImages();
        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000);

        vi.advanceTimersByTime(3000);
        images.made[0].onload!();

        await expect(loading).resolves.toBeNull();
    });

    it('stops the clock once the image is in, so a run leaves no timer behind', async () => {
        vi.useFakeTimers();
        const images = fakeImages();
        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000);

        images.made[0].onload!();
        await loading;

        expect(vi.getTimerCount()).toBe(0);
    });

    it('never rejects — a throw here would hide the only Save link the guest has', async () => {
        const images = fakeImages();
        const rejected = vi.fn();

        const loading = loadBrandingImage('/e/PARTY2/logo', images.create, 3000).catch(rejected);
        images.made[0].onerror!();
        await loading;

        expect(rejected).not.toHaveBeenCalled();
    });
});
