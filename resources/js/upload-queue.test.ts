import { describe, expect, it, vi } from 'vitest';
import { uploadAll, type QueuedUpload } from './upload-queue';

const instantWait = () => Promise.resolve();

function queued(slot: number, kind: 'original' | 'strip' = 'original'): QueuedUpload {
    return { blob: new Blob(['x']), kind, slot };
}

describe('uploadAll', () => {
    it('sends files one at a time in the order given', async () => {
        const sent: number[] = [];
        const send = async (upload: QueuedUpload) => {
            sent.push(upload.slot);
        };

        await uploadAll([queued(0, 'strip'), queued(1), queued(2)], send, () => {}, instantWait);

        expect(sent).toEqual([0, 1, 2]);
    });

    it('reports progress after each file', async () => {
        const progress: number[] = [];

        await uploadAll([queued(0), queued(1)], async () => {}, (n) => progress.push(n), instantWait);

        expect(progress).toEqual([1, 2]);
    });

    it('retries a failed upload and then continues', async () => {
        const attempts: number[] = [];
        const send = vi.fn(async (upload: QueuedUpload) => {
            attempts.push(upload.slot);
            if (upload.slot === 0 && attempts.length === 1) throw new Error('flaky wifi');
        });

        await uploadAll([queued(0), queued(1)], send, () => {}, instantWait);

        expect(attempts).toEqual([0, 0, 1]);
    });

    it('gives up after two retries with growing pauses and surfaces the error', async () => {
        const waits: number[] = [];
        const recordingWait = (ms: number) => {
            waits.push(ms);
            return Promise.resolve();
        };
        const send = vi.fn(async () => {
            throw new Error('the venue wifi is a lie');
        });

        await expect(uploadAll([queued(0)], send, () => {}, recordingWait))
            .rejects.toThrow('the venue wifi is a lie');
        expect(send).toHaveBeenCalledTimes(3);
        expect(waits).toEqual([1000, 3000]);
    });
});
