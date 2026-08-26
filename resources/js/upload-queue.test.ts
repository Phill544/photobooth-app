import { describe, expect, it, vi } from 'vitest';
import { OFFLINE_HOLD_MS, uploadAll, type QueuedUpload } from './upload-queue';
import { uploadFailure } from './upload';

const instantWait = () => Promise.resolve();
const alwaysOnline = { online: () => true, whenOnline: () => Promise.resolve() };
// Mid-band jitter, so a recorded delay is the plain base value.
const midJitter = () => 0.5;

const deps = { wait: instantWait, net: alwaysOnline, random: midJitter };

function queued(slot: number, kind: 'original' | 'strip' = 'original'): QueuedUpload {
    return { blob: new Blob(['x']), kind, slot };
}

function recordingWait(waits: number[]) {
    return (ms: number) => {
        waits.push(ms);
        return Promise.resolve();
    };
}

describe('uploadAll', () => {
    it('sends files one at a time in the order given', async () => {
        const sent: number[] = [];
        const send = async (upload: QueuedUpload) => {
            sent.push(upload.slot);
        };

        await uploadAll([queued(0, 'strip'), queued(1), queued(2)], send, () => {}, deps);

        expect(sent).toEqual([0, 1, 2]);
    });

    it('reports progress after each file', async () => {
        const progress: number[] = [];

        await uploadAll([queued(0), queued(1)], async () => {}, (n) => progress.push(n), deps);

        expect(progress).toEqual([1, 2]);
    });

    it('retries a failed upload and then continues', async () => {
        const attempts: number[] = [];
        const send = vi.fn(async (upload: QueuedUpload) => {
            attempts.push(upload.slot);
            if (upload.slot === 0 && attempts.length === 1) throw new Error('flaky wifi');
        });

        await uploadAll([queued(0), queued(1)], send, () => {}, deps);

        expect(attempts).toEqual([0, 0, 1]);
    });

    it('gives up after four retries with a growing tail and surfaces the error', async () => {
        const waits: number[] = [];
        const send = vi.fn(async () => {
            throw new Error('the venue wifi is a lie');
        });

        await expect(uploadAll([queued(0)], send, () => {}, { ...deps, wait: recordingWait(waits) }))
            .rejects.toThrow('the venue wifi is a lie');
        expect(send).toHaveBeenCalledTimes(5);
        expect(waits).toEqual([1000, 3000, 8000, 20000]);
    });

    it('jitters each pause by a quarter either way', async () => {
        const waits: number[] = [];
        const send = vi.fn(async () => {
            throw new Error('down');
        });

        await expect(uploadAll([queued(0)], send, () => {}, { ...deps, wait: recordingWait(waits), random: () => 0 }))
            .rejects.toThrow();
        expect(waits).toEqual([750, 2250, 6000, 15000]);

        waits.length = 0;
        await expect(uploadAll([queued(0)], send, () => {}, { ...deps, wait: recordingWait(waits), random: () => 1 }))
            .rejects.toThrow();
        expect(waits).toEqual([1250, 3750, 10000, 25000]);
    });

    it('stops at once when the booth closed mid-upload', async () => {
        const send = vi.fn(async () => {
            throw uploadFailure(410, null);
        });

        await expect(uploadAll([queued(0), queued(1)], send, () => {}, deps))
            .rejects.toMatchObject({ kind: 'closed' });
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('stops at once when the server rejected the file', async () => {
        const send = vi.fn(async () => {
            throw uploadFailure(422, null);
        });

        await expect(uploadAll([queued(0)], send, () => {}, deps)).rejects.toMatchObject({ kind: 'rejected' });
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('waits as long as a throttled server asked, when that is longer than the backoff', async () => {
        const waits: number[] = [];
        let attempts = 0;
        const send = vi.fn(async () => {
            attempts += 1;
            if (attempts === 1) throw uploadFailure(429, '30');
        });

        await uploadAll([queued(0)], send, () => {}, { ...deps, wait: recordingWait(waits) });

        expect(waits).toEqual([30000]);
    });

    it('keeps its own backoff when a throttled server asked for less', async () => {
        const waits: number[] = [];
        let attempts = 0;
        const send = vi.fn(async () => {
            attempts += 1;
            if (attempts === 1) throw uploadFailure(429, '0');
        });

        await uploadAll([queued(0)], send, () => {}, { ...deps, wait: recordingWait(waits) });

        expect(waits).toEqual([1000]);
    });

    it('waits for the network instead of burning an attempt while offline', async () => {
        let online = false;
        const holds: number[] = [];
        const net = {
            online: () => online,
            whenOnline: (limit: number) => {
                holds.push(limit);
                online = true; // the wifi comes back during the hold
                return Promise.resolve();
            },
        };
        const send = vi.fn(async () => {});

        await uploadAll([queued(0)], send, () => {}, { ...deps, net });

        expect(holds).toEqual([OFFLINE_HOLD_MS]);
        expect(send).toHaveBeenCalledTimes(1); // the attempt was held, not spent
    });

    it('holds for the signal before every attempt, not just the first', async () => {
        let online = true;
        const holds: number[] = [];
        const send = vi.fn(async () => {
            online = false; // the access point drops as each file goes up
            throw new Error('lost it');
        });
        const net = {
            online: () => online,
            whenOnline: (limit: number) => {
                holds.push(limit);
                online = true;
                return Promise.resolve();
            },
        };

        await expect(uploadAll([queued(0)], send, () => {}, { ...deps, net }))
            .rejects.toThrow('lost it');
        // Offline going into every attempt after the first, so held before each.
        expect(holds).toEqual([OFFLINE_HOLD_MS, OFFLINE_HOLD_MS, OFFLINE_HOLD_MS, OFFLINE_HOLD_MS]);
        expect(send).toHaveBeenCalledTimes(5);
    });

    it('gives up rather than holding forever when the signal never comes back', async () => {
        const holds: number[] = [];
        const net = {
            online: () => false,
            whenOnline: (limit: number) => {
                holds.push(limit); // every hold is bounded, so the queue always ends
                return Promise.resolve();
            },
        };
        const send = vi.fn(async () => {
            throw uploadFailure(0, null);
        });

        // The guest must reach the failed screen — it is the only one that can
        // save their strip.
        await expect(uploadAll([queued(0)], send, () => {}, { ...deps, net }))
            .rejects.toMatchObject({ kind: 'network' });
        expect(holds).toEqual(Array(5).fill(OFFLINE_HOLD_MS));
        expect(send).toHaveBeenCalledTimes(5);
    });
});
