import 'fake-indexeddb/auto';
import { beforeEach, describe, expect, it } from 'vitest';
import {
    dropPendingSession,
    loadPendingSessions,
    savePendingSession,
    STALE_AFTER_MS,
    type PendingSession,
} from './pending-session';

const NOW = 1_800_000_000_000;

function session(overrides: Partial<PendingSession> = {}): PendingSession {
    return {
        group: 'aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10',
        eventCode: 'PARTY2',
        savedAt: NOW,
        uploads: [
            { blob: new Blob(['strip-bytes'], { type: 'image/jpeg' }), kind: 'strip', slot: 0 },
            { blob: new Blob(['shot-bytes'], { type: 'image/jpeg' }), kind: 'original', slot: 1 },
        ],
        ...overrides,
    };
}

beforeEach(async () => {
    await new Promise<void>((resolve) => {
        const request = indexedDB.deleteDatabase('photobooth');
        request.onsuccess = () => resolve();
        request.onerror = () => resolve();
    });
});

describe('the pending session store', () => {
    it('hands back a session saved for this event', async () => {
        await savePendingSession(session());

        const pending = await loadPendingSessions('PARTY2', NOW);

        expect(pending).toHaveLength(1);
        expect(pending[0].group).toBe('aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10');
    });

    it('keeps the photo bytes intact across the round trip', async () => {
        await savePendingSession(session());

        const [pending] = await loadPendingSessions('PARTY2', NOW);

        expect(pending.uploads.map((upload) => upload.slot)).toEqual([0, 1]);
        expect(pending.uploads[0].kind).toBe('strip');
        expect(await pending.uploads[0].blob.text()).toBe('strip-bytes');
        expect(pending.uploads[0].blob.type).toBe('image/jpeg');
    });

    it('leaves another event’s session alone', async () => {
        await savePendingSession(session({ group: 'here', eventCode: 'PARTY2' }));
        await savePendingSession(session({ group: 'elsewhere', eventCode: 'OTHER2' }));

        expect((await loadPendingSessions('PARTY2', NOW)).map((s) => s.group)).toEqual(['here']);
        expect((await loadPendingSessions('OTHER2', NOW)).map((s) => s.group)).toEqual(['elsewhere']);
    });

    it('drains the oldest strip first', async () => {
        await savePendingSession(session({ group: 'second', savedAt: NOW - 1000 }));
        await savePendingSession(session({ group: 'first', savedAt: NOW - 9000 }));

        expect((await loadPendingSessions('PARTY2', NOW)).map((s) => s.group)).toEqual(['first', 'second']);
    });

    it('forgets a session nobody came back for within a day', async () => {
        await savePendingSession(session({ group: 'ancient', savedAt: NOW - STALE_AFTER_MS - 1 }));

        expect(await loadPendingSessions('PARTY2', NOW)).toEqual([]);
        // Dropped for good, not just skipped — the event is long over.
        expect(await loadPendingSessions('PARTY2', NOW - STALE_AFTER_MS)).toEqual([]);
    });

    it('still offers a session saved hours ago', async () => {
        await savePendingSession(session({ savedAt: NOW - STALE_AFTER_MS + 1000 }));

        expect(await loadPendingSessions('PARTY2', NOW)).toHaveLength(1);
    });

    it('clears a stale session belonging to another event too', async () => {
        await savePendingSession(session({ group: 'ancient', eventCode: 'OTHER2', savedAt: NOW - STALE_AFTER_MS - 1 }));

        await loadPendingSessions('PARTY2', NOW);

        expect(await loadPendingSessions('OTHER2', NOW)).toEqual([]);
    });

    it('drops a session once it has landed', async () => {
        await savePendingSession(session());

        await dropPendingSession('aa0f7c69-3c1e-4d3c-9c39-58b7d31f2f10');

        expect(await loadPendingSessions('PARTY2', NOW)).toEqual([]);
    });

    it('keeps one record per session, however often it is saved', async () => {
        await savePendingSession(session({ savedAt: NOW - 5000 }));
        await savePendingSession(session({ savedAt: NOW }));

        const pending = await loadPendingSessions('PARTY2', NOW);

        expect(pending).toHaveLength(1);
        expect(pending[0].savedAt).toBe(NOW);
    });
});
