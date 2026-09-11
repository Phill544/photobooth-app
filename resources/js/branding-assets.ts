// Loading the artwork a strip is branded with, on a clock.
//
// The strip is composed exactly once, from whatever `branding` holds at that
// moment, so anything still in flight is simply absent from the guest's only
// copy — and nothing recomposes to put it back. The logo used to be preloaded
// fire-and-forget with no `onerror` at all, which cost a caption when it lost
// the race and said nothing.
//
// Awaiting the load fixes that and introduces a worse failure: venue wifi that
// accepts the connection and then stalls fires neither handler, so a plain
// await would hold the review screen — and the Save link that is the guest's
// only way to keep the night — shut for good. Hence the deadline: this settles,
// always, and the strip goes out branded or plain rather than not at all.
//
// The image is made by the caller so this is a pure module with no DOM: the
// booth passes `Image`, the tests pass an object and fire the handlers by hand.

export interface LoadableImage {
    src: string;
    onload: ((this: any, ev?: any) => any) | null;
    onerror: ((this: any, ev?: any) => any) | null;
}

export function loadBrandingImage<T extends LoadableImage>(
    source: string | null | undefined,
    create: () => T,
    deadlineMs: number,
): Promise<T | null> {
    if (!source) return Promise.resolve(null);

    return new Promise((resolve) => {
        const image = create();
        const timer = setTimeout(() => resolve(null), deadlineMs);

        // resolve() ignores every call after the first, so a load that lands
        // after the deadline changes nothing and needs no flag to say so.
        const settle = (value: T | null) => {
            clearTimeout(timer);
            resolve(value);
        };

        image.onload = () => settle(image);
        image.onerror = () => settle(null);
        image.src = source;
    });
}
