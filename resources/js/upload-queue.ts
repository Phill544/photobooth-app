export type QueuedUpload = {
    blob: Blob;
    kind: 'original' | 'strip';
    slot: number;
};

type SendFn = (upload: QueuedUpload) => Promise<void>;
type WaitFn = (ms: number) => Promise<void>;

const RETRY_DELAYS_MS = [1000, 3000];

const realWait: WaitFn = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// One file at a time: small requests survive flaky event wifi better, and the
// server dedupes on (group, slot) so a retry can never duplicate a photo.
export async function uploadAll(
    uploads: QueuedUpload[],
    send: SendFn,
    onProgress: (uploaded: number) => void,
    wait: WaitFn = realWait,
): Promise<void> {
    let uploaded = 0;
    for (const upload of uploads) {
        await sendWithRetries(upload, send, wait);
        uploaded += 1;
        onProgress(uploaded);
    }
}

async function sendWithRetries(upload: QueuedUpload, send: SendFn, wait: WaitFn): Promise<void> {
    for (let attempt = 0; ; attempt++) {
        try {
            await send(upload);
            return;
        } catch (error) {
            if (attempt >= RETRY_DELAYS_MS.length) throw error;
            await wait(RETRY_DELAYS_MS[attempt]);
        }
    }
}
