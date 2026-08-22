// Keeps the screen awake during capture so it doesn't dim mid-countdown.
// The OS releases the lock whenever the tab backgrounds, so it must be
// reacquired on return — call reacquireWakeLock() from a visibilitychange
// handler. A no-op wherever the API is missing or denied (Low Power Mode).

let sentinel: WakeLockSentinel | null = null;
let wanted = false;

export async function requestWakeLock(): Promise<void> {
    wanted = true;
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
    try {
        sentinel = await navigator.wakeLock.request('screen');
        sentinel.addEventListener('release', () => { sentinel = null; });
    } catch {
        // NotAllowedError / Low Power Mode — the screen may just dim; not worth surfacing.
    }
}

export async function releaseWakeLock(): Promise<void> {
    wanted = false;
    try {
        await sentinel?.release();
    } catch {
        // already gone
    }
    sentinel = null;
}

export function reacquireWakeLock(): void {
    if (wanted && !sentinel && document.visibilityState === 'visible') void requestWakeLock();
}
