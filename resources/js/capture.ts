import { cameraIsLive, grabFrame, onCameraLost, startCamera, toJpegBlob } from './camera';
import { nextState, type FlowEvent, type FlowState } from './capture-flow';
import { androidChromeIntent, cameraSupported, detectInApp, isIOS } from './in-app';
import { FILTERS, filterFor, type Filter } from './filters';
import { dropPendingSession, isStale, loadPendingSessions, savePendingSession } from './pending-session';
import { composeStrip, type Branding } from './strip-compose';
import { stripTheme } from './strip-theme';
import { templateFor } from './templates';
import { isTerminal, UploadError, uploadPhoto, type UploadFailureKind } from './upload';
import { uploadAll, type QueuedUpload } from './upload-queue';
import { reacquireWakeLock, releaseWakeLock, requestWakeLock } from './wake-lock';

const eventCode = document.body.dataset.eventCode!;
const eventName = document.body.dataset.eventName!;
const template = templateFor(document.body.dataset.template ?? '');
const cellAspect = template.cellWidth / template.cellHeight;
const branding: Branding = {
    ...stripTheme(document.body.dataset.theme ?? ''),
    caption: document.body.dataset.caption || eventName,
    logo: null,
};

// Preload the event's logo (same-origin, so it won't taint the strip canvas).
// It's a small image and loads well before the review screen; if it somehow
// isn't ready, the strip falls back to the caption text.
const logoUrl = document.body.dataset.logo;
if (logoUrl) {
    const logo = new Image();
    logo.onload = () => { branding.logo = logo; };
    logo.src = logoUrl;
}

const $ = <T extends HTMLElement>(sel: string) => document.querySelector<T>(sel)!;

const video = $<HTMLVideoElement>('#preview');
const countdownNumber = $('#countdown-number');
const shotLabel = $('#shot-label');
const flashOverlay = $('#flash-overlay');
const stripPreview = $<HTMLImageElement>('#strip-preview');
const uploadProgress = $('#upload-progress');
const errorMessage = $('#error-message');
const rotateOverlay = $('#rotate-overlay');
const offlineHint = $('#offline-hint');
const resumeNotice = $('#resume-notice');
const shareError = $('#share-error');

// Every top-level section, flow-driven and takeover alike.
const sections: Record<string, HTMLElement> = {
    start: $('#start-screen'),
    camera: $('#camera-screen'),
    review: $('#review-screen'),
    uploading: $('#uploading-screen'),
    uploadFailed: $('#upload-failed-screen'),
    done: $('#done-screen'),
    cameraLost: $('#camera-lost-screen'),
    denied: $('#denied-screen'),
    inApp: $('#in-app-screen'),
    error: $('#error-screen'),
};

let state: FlowState = { screen: 'start' };
let stream: MediaStream | null = null;
let shots: HTMLCanvasElement[] = [];
let strip: HTMLCanvasElement | null = null;
let tickTimer: ReturnType<typeof setTimeout> | undefined;
let openingCamera = false;
let takeover = false; // a denied/in-app/error screen is showing; the flow is suspended
let failed = false; // the terminal error screen; only Reload leaves it

let pendingUploads: QueuedUpload[] | null = null;
let pendingGroup: string | null = null;
let stripFile: File | null = null;
let stripUrl: string | null = null;
let canShareStrip = false;
let activeFilter: Filter = filterFor('none');

const filterRail = $('#filter-rail');
const filterControls = $('#filter-controls');
const filterBadge = $('#filter-badge');
const customiseStart = $('#customise-start');
const hudTop = $('.hud--top');
const shotDots = $('#shot-dots');
const cameraFrame = $('.camera-frame');
const saveReview = $<HTMLAnchorElement>('#save-review');
const saveFailed = $<HTMLAnchorElement>('#save-failed');

// Only nag touch devices held sideways — never a landscape desktop.
const landscape = matchMedia('(orientation: landscape) and (pointer: coarse)');

// The preview is sized from the template's cell aspect, so the guest frames
// exactly what grabFrame will crop; the black stage around it holds the HUD.
cameraFrame.style.setProperty('--cell-aspect', String(cellAspect));

// One dash per shot in the run, lit as the guest works through them.
for (let i = 0; i < template.cellCount; i++) shotDots.appendChild(document.createElement('span'));

// The template owns the shot count, so it also writes the booth's promise.
$('#promise').textContent =
    `${template.cellCount === 1 ? 'One photo' : `${template.cellCount} photos`}. One strip. Yours to keep.`;

// What the guest is told when an upload doesn't land, and whether Retry is worth
// offering. A closed booth and a rejected file can't be retried into working.
const UPLOAD_FAILURE_COPY: Record<UploadFailureKind, { eyebrow: string; title: string; detail: string; retry: boolean }> = {
    closed: {
        eyebrow: 'The booth',
        title: 'The booth just closed',
        detail: "This event stopped taking photos, so your strip didn't make it to the album — but it's still yours. Save it to your phone.",
        retry: false,
    },
    throttled: {
        eyebrow: 'Almost there',
        title: 'The album is busy',
        detail: 'A lot of strips are going up at once. Give it a moment, then try again.',
        retry: true,
    },
    rejected: {
        eyebrow: 'Sorry',
        title: "The album wouldn't take it",
        detail: "Something about these photos wasn't accepted, and trying again won't change it. Save your strip to your phone.",
        retry: false,
    },
    network: {
        eyebrow: 'Almost there',
        title: "Upload didn't finish",
        detail: "Some photos didn't make it up — check your signal and try again.",
        retry: true,
    },
};

// The strip is queued first (see shareToAlbum), so one landed file means the
// strip itself is already in the album — and a booth that closed after that only
// cost the guest the leftover shots. Saying "your strip didn't make it" then is
// simply untrue, and this is the moment a guest is least willing to be lied to.
const CLOSED_MID_UPLOAD = {
    eyebrow: 'The booth',
    title: 'The booth closed',
    detail: "Your strip made it to the album — the last few shots didn't. Save the strip to your phone too if you like.",
    retry: false,
};

function failureCopy(reason: UploadFailureKind, uploaded: number) {
    if (reason === 'closed' && uploaded > 0) return CLOSED_MID_UPLOAD;

    return UPLOAD_FAILURE_COPY[reason];
}

function showOnly(id: string) {
    for (const [name, el] of Object.entries(sections)) el.hidden = name !== id;
}

function dispatch(event: FlowEvent) {
    if (failed) return;
    const previous = state;
    state = nextState(state, event, template);
    render();
    runEffects(previous);
}

function render() {
    if (failed || takeover) return;
    const onCamera = state.screen === 'countdown' || state.screen === 'flash' || state.screen === 'customise';
    showOnly(onCamera ? 'camera' : state.screen);
    // Picking a look owns the whole frame — countdown and shot HUD step aside.
    const customising = state.screen === 'customise';
    filterControls.hidden = !customising;
    countdownNumber.hidden = customising;
    hudTop.hidden = customising;
    shotDots.hidden = customising;

    if (state.screen === 'countdown') countdownNumber.textContent = String(state.secondsLeft);
    if (state.screen === 'countdown' || state.screen === 'flash') {
        const { shotIndex } = state;
        shotLabel.textContent = `Shot ${shotIndex + 1} / ${template.cellCount}`;
        [...shotDots.children].forEach((dot, index) => dot.classList.toggle('lit', index <= shotIndex));
    }
    if (state.screen === 'uploading') {
        uploadProgress.textContent = `Uploading ${state.uploaded + 1} of ${state.total}…`;
    }
    if (state.screen === 'uploadFailed') {
        const copy = failureCopy(state.reason, state.uploaded);
        $('#upload-failed-eyebrow').textContent = copy.eyebrow;
        $('#upload-failed-title').textContent = copy.title;
        $('#upload-failed-detail').textContent = copy.detail;
        $('#upload-retry').hidden = !copy.retry;
    }
}

function runEffects(previous: FlowState) {
    if (state.screen === 'countdown') scheduleTick();
    if (state.screen === 'customise' && previous.screen !== 'customise') paintLookThumbnails();
    if (state.screen === 'flash' && previous.screen !== 'flash') captureShot();
    if (state.screen === 'review' && previous.screen !== 'review') { showStripPreview(); prepareStripShare(); }
    if (state.screen === 'uploading' && previous.screen !== 'uploading') void runUpload();
    if (state.screen === 'done' && previous.screen !== 'done') void releaseWakeLock();
    // Reset can now land on the start screen holding the lock the camera took —
    // nothing there needs the screen kept awake, and it is the guest's battery.
    if (state.screen === 'start' && previous.screen !== 'start') void releaseWakeLock();
}

// A single tracked timer: no tap or stale dispatch can spawn a second chain.
function scheduleTick() {
    clearTimeout(tickTimer);
    tickTimer = setTimeout(() => {
        if (state.screen !== 'countdown') return;
        if (landscape.matches) { scheduleTick(); return; } // paused while sideways; the overlay covers it
        dispatch({ type: 'tick' });
    }, 1000);
}

function captureShot() {
    if (state.screen !== 'flash') return;
    // The preview is mirrored so guests can frame like a mirror, but the saved
    // frame is NOT mirrored, so text/signs in the strip read the right way round.
    shots[state.shotIndex] = grabFrame(video, false, cellAspect, activeFilter);
    flashOverlay.classList.add('flashing');
    setTimeout(() => {
        flashOverlay.classList.remove('flashing');
        dispatch({ type: 'shotCaptured' });
    }, 250);
}

function showStripPreview() {
    strip = composeStrip(shots, template, branding);
    stripPreview.src = strip.toDataURL('image/jpeg', 0.85);
}

// Encoding five JPEGs can fail on a phone that is low on memory, and until they
// are encoded the strip exists only as a canvas on the review screen. That must
// never reach the global handler: showError() hides this screen and with it the
// only Save link the guest has.
function shareFailed() {
    shareError.hidden = false;
}

async function shareToAlbum() {
    if (state.screen !== 'review') return;
    shareError.hidden = true;
    pendingGroup = crypto.randomUUID();
    pendingUploads = [
        { blob: await toJpegBlob(strip!), kind: 'strip', slot: 0 },
        ...(await Promise.all(shots.map(async (shot, index): Promise<QueuedUpload> => ({
            blob: await toJpegBlob(shot),
            kind: 'original',
            slot: index + 1,
        })))),
    ];

    // Written to the device before the first byte goes up, so a closed tab or a
    // flat battery mid-upload doesn't cost the guest the strip they just shared.
    await savePendingSession({
        group: pendingGroup,
        eventCode,
        savedAt: Date.now(),
        uploads: pendingUploads,
    }).catch(ignoreStoreFailure);

    dispatch({ type: 'share' }); // -> uploading; runEffects kicks off runUpload
}

const sendUpload = (group: string, upload: QueuedUpload) =>
    uploadPhoto(eventCode, upload.blob, { kind: upload.kind, slot: upload.slot, group }).then(() => {});

async function runUpload() {
    try {
        await uploadAll(
            pendingUploads!,
            (upload) => sendUpload(pendingGroup!, upload),
            () => dispatch({ type: 'photoUploaded' }),
        );
        forget(pendingGroup!);
    } catch (error) {
        // Already-sent slots dedup on the server, so a retry only re-sends the failures.
        dispatch({
            type: 'uploadFailed',
            reason: error instanceof UploadError ? error.kind : 'network',
        });
        // A closed booth or a rejected file will be just as closed and just as
        // rejected on the next page load; a lost signal won't.
        if (error instanceof UploadError && isTerminal(error)) forget(pendingGroup!);
    }
}

// --- Finishing a share the guest already asked for ---

// The store is a safety net, never a dependency: a device that won't give us one
// (private-mode Safari) must still shoot, share and save exactly as before.
const ignoreStoreFailure = () => {};

function forget(group: string) {
    void dropPendingSession(group).catch(ignoreStoreFailure);
}

// A share interrupted by a closed tab, a flat battery or a walk out of range.
// The server dedupes per (group, slot), so finishing it costs nothing — the
// guest already tapped Share, so they are told, not asked.
async function resumePending() {
    const now = Date.now();
    const pending = await loadPendingSessions(eventCode, now);
    if (!pending.length) return;

    resumeNotice.hidden = false;
    resumeNotice.textContent = 'Finishing an earlier upload…';

    for (const session of pending) {
        try {
            await uploadAll(session.uploads, (upload) => sendUpload(session.group, upload), () => {});
            forget(session.group);
        } catch (error) {
            // A closed booth or a rejected file won't be fixed by trying later;
            // and a session past its window has just had the last go it was
            // being kept for.
            const done = (error instanceof UploadError && isTerminal(error)) || isStale(session, now);
            if (done) forget(session.group);
            resumeNotice.textContent = "An earlier strip still hasn't made it up.";
            return;
        }
    }

    resumeNotice.textContent = 'Your earlier strip made it to the album.';
}

// --- Save / share the strip image (built at review, so the tap on either the
// review or the done screen still has its user activation) ---
function prepareStripShare() {
    if (!strip) return;
    strip.toBlob((blob) => {
        if (!blob) return;
        stripFile = new File([blob], `${eventName}-strip.jpg`, { type: 'image/jpeg' });
        if (stripUrl) URL.revokeObjectURL(stripUrl);
        stripUrl = URL.createObjectURL(blob);

        canShareStrip = !!(navigator.canShare && navigator.canShare({ files: [stripFile] }));
        $('#save-strip').hidden = !canShareStrip;
        $('#save-fallback').hidden = canShareStrip;
        $<HTMLImageElement>('#save-image').src = stripUrl;

        // Both save affordances are plain download links; where the platform can
        // share a file, a click intercepts and opens the share sheet instead.
        for (const link of [saveReview, saveFailed, $<HTMLAnchorElement>('#save-download')]) {
            link.href = stripUrl;
            link.download = `${eventName}-strip.jpg`;
            link.removeAttribute('aria-disabled'); // encoding is done; the link is live
        }
    }, 'image/jpeg', 0.85);
}

async function saveStrip() {
    if (!stripFile) return;
    try {
        await navigator.share({ files: [stripFile] }); // files only — iOS drops url/text when files are present
    } catch (err) {
        if ((err as DOMException).name === 'AbortError') return; // guest dismissed the sheet
        // The sheet can't take the file after all — hand every save affordance
        // back to the download path (a second tap on "Save to phone" downloads).
        canShareStrip = false;
        $('#save-strip').hidden = true;
        $('#save-fallback').hidden = false;
    }
}

// Serializes camera access: concurrent taps are ignored, a live stream is reused,
// a dead/superseded one is stopped first. getUserMedia rejections propagate to begin().
async function ensureCamera(): Promise<boolean> {
    if (openingCamera) return false;
    if (stream && cameraIsLive(stream)) return true;
    openingCamera = true;
    try {
        stream?.getTracks().forEach((track) => track.stop());
        const fresh = await startCamera(video);
        stream = fresh;
        onCameraLost(fresh, () => { if (fresh === stream) dispatch({ type: 'cameraLost' }); });
        return true;
    } finally {
        openingCamera = false;
    }
}

// Acquires the camera (with the shared error handling), then runs onReady.
async function enterCamera(onReady: () => void) {
    if (!cameraSupported()) { showTakeover('inApp'); return; }
    try {
        if (await ensureCamera()) {
            clearTakeover();
            void requestWakeLock();
            onReady();
        }
    } catch (err) {
        handleCameraError(err);
    }
}

// Quick shoot: no filter, straight into the countdown.
function beginQuick() {
    setFilter('none');
    void enterCamera(() => dispatch({ type: 'start' }));
}

// Customise: opens the live preview so the guest can pick a filter first.
function beginCustomise() {
    void enterCamera(() => dispatch({ type: 'customise' }));
}

function setFilter(key: string) {
    activeFilter = filterFor(key);
    const filtered = activeFilter.key !== 'none';
    video.style.filter = filtered ? activeFilter.css : '';
    filterBadge.hidden = !filtered;
    filterBadge.textContent = activeFilter.label;
    customiseStart.textContent = filtered ? `Shoot with ${activeFilter.label}` : 'Start shooting';
    for (const look of filterRail.children) {
        look.classList.toggle('selected', (look as HTMLElement).dataset.filter === activeFilter.key);
    }
}

// A still of the live preview, mirrored to match it, drawn small behind every
// look so the guest sees their own face under each filter.
function paintLookThumbnails() {
    if (!video.videoWidth) return;
    const frame = grabFrame(video, true, 4 / 5, filterFor('none'));
    const thumb = document.createElement('canvas');
    thumb.width = 128;
    thumb.height = 160;
    thumb.getContext('2d')!.drawImage(frame, 0, 0, thumb.width, thumb.height);
    const url = thumb.toDataURL('image/jpeg', 0.7);
    for (const shot of filterRail.querySelectorAll<HTMLElement>('.shot')) {
        shot.style.backgroundImage = `url(${url})`;
    }
}

// Retakes can happen long after a phone locked on review/done (iOS kills the stream),
// so re-ensure a live camera before every re-entry into a countdown.
async function retake() {
    try {
        if (await ensureCamera()) {
            void requestWakeLock();
            dispatch({ type: 'retake' });
        }
    } catch (err) {
        handleCameraError(err);
    }
}

// A denied permission is recoverable via settings; anything else (no camera,
// busy hardware, locked-down webview) gets the browser-escape screen.
function handleCameraError(err: unknown) {
    if ((err as DOMException).name === 'NotAllowedError') showTakeover('denied');
    else showTakeover('inApp');
}

function showTakeover(id: 'denied' | 'inApp') {
    takeover = true;
    // Recovery from a takeover always starts a fresh capture, so drop back to
    // 'start' — otherwise the retry buttons would render the stale review/done
    // screen the flow was on when the camera failed.
    state = { screen: 'start' };
    shots = [];
    if (id === 'denied') {
        $('#denied-ios').hidden = !isIOS();
        $('#denied-android').hidden = isIOS();
    }
    if (id === 'inApp') {
        const openChrome = $<HTMLAnchorElement>('#open-chrome');
        openChrome.hidden = isIOS();
        if (!isIOS()) openChrome.href = androidChromeIntent(location.href);
        $('#open-safari').hidden = !isIOS(); // the "Open in Safari" hint is iOS-only; copy-link stays for all
    }
    showOnly(id);
}

function clearTakeover() {
    takeover = false;
}

// showTakeover already parked the flow on 'start', so leaving one is just
// showing that screen again — which is what both of its exits want.
function leaveTakeover() {
    clearTakeover();
    showOnly('start');
}

function showError(message: string) {
    failed = true;
    void releaseWakeLock();
    errorMessage.textContent = message;
    showOnly('error');
}

// iOS kills the stream on lock/background; the 'ended' event is unreliable, so
// also re-check on return — and reacquire the wake lock the OS dropped.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    reacquireWakeLock();
    if (stream && !cameraIsLive(stream)) dispatch({ type: 'cameraLost' });
});

// The queue holds while the phone has no signal, so the progress line stops
// moving. Say why, or a stalled upload reads as a broken booth.
function syncSignal() {
    offlineHint.hidden = navigator.onLine;
}
addEventListener('online', syncSignal);
addEventListener('offline', syncSignal);

function syncOrientation() {
    rotateOverlay.hidden = !landscape.matches;
}
landscape.addEventListener('change', syncOrientation);

window.addEventListener('unhandledrejection', (event) => showError(String(event.reason)));
window.addEventListener('error', (event) => showError(event.message));

// Build the look picker from the filter registry (single source of truth).
for (const filter of FILTERS) {
    const look = document.createElement('button');
    look.type = 'button';
    look.className = 'look';
    look.dataset.filter = filter.key;
    const swatch = document.createElement('span');
    swatch.className = 'swatch';
    const shot = document.createElement('span');
    shot.className = 'shot';
    if (filter.css !== 'none') shot.style.filter = filter.css;
    swatch.appendChild(shot);
    look.append(swatch, filter.label);
    look.addEventListener('click', () => setFilter(filter.key));
    filterRail.appendChild(look);
}
setFilter('none'); // marks the None look selected

$('#start').addEventListener('click', beginQuick);
$('#add-filter').addEventListener('click', beginCustomise);
// Re-ensure the camera first: a guest can linger on the customise screen long
// enough for the phone to lock and kill the stream (like retake).
$('#customise-start').addEventListener('click', () => void enterCamera(() => dispatch({ type: 'start' })));
$('#share').addEventListener('click', () => void shareToAlbum().catch(shareFailed));
$('#retake').addEventListener('click', retake); // same guest, same run — keep their filter
// Every screen a guest can be left standing on gets the same door: back to the
// start, where "Start shooting" and "Pick a look" already live. It is where a
// real booth leaves you, and on the guest's own phone (D2) there is no last
// guest whose look needs dropping — beginQuick clears the filter anyway.
for (const id of ['#again', '#review-back', '#failed-again', '#lost-back', '#customise-back']) {
    $(id).addEventListener('click', () => dispatch({ type: 'reset' }));
}
$('#camera-retry').addEventListener('click', async () => {
    try {
        if (await ensureCamera()) { void requestWakeLock(); dispatch({ type: 'cameraBack' }); }
    } catch (err) {
        handleCameraError(err);
    }
});
$('#upload-retry').addEventListener('click', () => dispatch({ type: 'retryUpload' }));
$('#denied-retry').addEventListener('click', beginQuick);
$('#denied-back').addEventListener('click', leaveTakeover);
$('#save-strip').addEventListener('click', saveStrip);
// A plain download link by default, upgraded to the share sheet where the
// platform can take a file — which is what a phone actually wants.
for (const link of [saveReview, saveFailed]) {
    link.addEventListener('click', (event) => {
        if (!canShareStrip) return;
        event.preventDefault();
        void saveStrip();
    });
}
$('#continue-anyway').addEventListener('click', (event) => { event.preventDefault(); leaveTakeover(); });

// An in-app browser (Instagram/Facebook/etc.) blocks getUserMedia — warn before the dead camera.
if (detectInApp(navigator.userAgent)) showTakeover('inApp');
syncOrientation();
syncSignal();
void resumePending().catch(ignoreStoreFailure);
