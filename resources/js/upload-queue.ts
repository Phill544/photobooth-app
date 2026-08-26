import { isTerminal, UploadError } from './upload';

export type QueuedUpload = {
    blob: Blob;
    kind: 'original' | 'strip';
    slot: number;
};

type SendFn = (upload: QueuedUpload) => Promise<void>;
type WaitFn = (ms: number) => Promise<void>;

// The two facts about the network the queue needs, behind a seam so the tests
// don't have to fake a browser.
type Network = {
    online: () => boolean;
    whenOnline: (limitMs: number) => Promise<void>;
};

type QueueDeps = {
    wait?: WaitFn;
    random?: () => number;
    net?: Network;
};

// Long enough a tail to outlast the usual event-wifi outage: a dropped access
// point, a walk out of range, a room that fills up.
const RETRY_DELAYS_MS = [1000, 3000, 8000, 20000];

// How long the queue holds for a signal before spending an attempt anyway.
// Long enough to ride out a wifi handover, and bounded: waiting forever would
// park the guest on the uploading screen, which is the one screen with no way
// to save their strip.
export const OFFLINE_HOLD_MS = 5000;

const realWait: WaitFn = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const realNetwork: Network = {
    online: () => navigator.onLine,
    whenOnline: (limitMs) => new Promise((resolve) => {
        const done = () => {
            clearTimeout(timer);
            removeEventListener('online', done);
            resolve();
        };
        const timer = setTimeout(done, limitMs);
        addEventListener('online', done);
    }),
};

// One file at a time: small requests survive flaky event wifi better, and the
// server dedupes on (group, slot) so a retry can never duplicate a photo.
export async function uploadAll(
    uploads: QueuedUpload[],
    send: SendFn,
    onProgress: (uploaded: number) => void,
    deps: QueueDeps = {},
): Promise<void> {
    const wait = deps.wait ?? realWait;
    const random = deps.random ?? Math.random;
    const net = deps.net ?? realNetwork;

    let uploaded = 0;
    for (const upload of uploads) {
        await sendWithRetries(upload, send, wait, random, net);
        uploaded += 1;
        onProgress(uploaded);
    }
}

async function sendWithRetries(
    upload: QueuedUpload,
    send: SendFn,
    wait: WaitFn,
    random: () => number,
    net: Network,
): Promise<void> {
    for (let attempt = 0; ; attempt++) {
        // Checked before every attempt, not just the first: an offline phone
        // would otherwise spend the whole retry budget on requests that can't
        // leave the device.
        if (!net.online()) await net.whenOnline(OFFLINE_HOLD_MS);

        try {
            await send(upload);
            return;
        } catch (error) {
            if (error instanceof UploadError && isTerminal(error)) throw error;
            if (attempt >= RETRY_DELAYS_MS.length) throw error;
            await wait(pauseFor(error, RETRY_DELAYS_MS[attempt], random));
        }
    }
}

// The server's Retry-After wins when it asks for longer than our own backoff —
// coming back sooner than it asked only earns another 429.
function pauseFor(error: unknown, base: number, random: () => number): number {
    const asked = error instanceof UploadError ? error.retryAfterMs : null;
    return Math.max(jittered(base, random), asked ?? 0);
}

// A quarter either way. A whole room loses the same access point at the same
// instant, and without jitter every phone comes back together and drops it again.
function jittered(base: number, random: () => number): number {
    return Math.round(base * (0.75 + random() * 0.5));
}
