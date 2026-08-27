import type { QueuedUpload } from './upload-queue';

// A session the guest has already asked us to share, held on the device until
// every file is up. The server dedupes on (group, slot), so re-sending after a
// reload costs nothing and can never duplicate a photo.
export type PendingSession = {
    group: string;
    eventCode: string;
    savedAt: number;
    uploads: QueuedUpload[];
};

const DB_NAME = 'photobooth';
const STORE = 'pending-sessions';

// A strip nobody has come back for in a day belongs to an event that has ended.
export const STALE_AFTER_MS = 24 * 60 * 60 * 1000;

export function isStale(session: PendingSession, now: number): boolean {
    return now - session.savedAt >= STALE_AFTER_MS;
}

export async function savePendingSession(session: PendingSession): Promise<void> {
    const db = await openDb();
    try {
        await run(db, 'readwrite', (store) => store.put(session));
    } finally {
        db.close();
    }
}

// Everything held for this event, oldest first — including sessions past their
// window, which get one last attempt rather than being deleted unsent: a guest
// opening the booth link a day later is a phone that has just found signal, with
// the strip still on it. Only sessions belonging to events the guest is NOT at
// are swept here; the caller drops a stale one once it has had its go.
export async function loadPendingSessions(eventCode: string, now: number): Promise<PendingSession[]> {
    const db = await openDb();
    try {
        const all = await run<PendingSession[]>(db, 'readonly', (store) => store.getAll());
        const abandoned = all.filter((session) => session.eventCode !== eventCode && isStale(session, now));
        if (abandoned.length) {
            await run(db, 'readwrite', (store) => {
                for (const session of abandoned) store.delete(session.group);
            });
        }

        return all
            .filter((session) => session.eventCode === eventCode)
            .sort((a, b) => a.savedAt - b.savedAt);
    } finally {
        db.close();
    }
}

export async function dropPendingSession(group: string): Promise<void> {
    const db = await openDb();
    try {
        await run(db, 'readwrite', (store) => store.delete(group));
    } finally {
        db.close();
    }
}

// Opened per operation and closed after: no long-lived handle to go stale while
// a phone sits locked on the review screen for an hour.
function openDb(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'group' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

// One transaction, whatever work the caller wants inside it, resolved when the
// transaction commits — so a write is on disk before the caller moves on.
function run<T = void>(
    db: IDBDatabase,
    mode: IDBTransactionMode,
    work: (store: IDBObjectStore) => IDBRequest | void,
): Promise<T> {
    return new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE, mode);
        const request = work(transaction.objectStore(STORE));
        transaction.oncomplete = () => resolve((request ? request.result : undefined) as T);
        transaction.onerror = () => reject(transaction.error);
        transaction.onabort = () => reject(transaction.error);
    });
}
