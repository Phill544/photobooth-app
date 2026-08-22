import { cameraIsLive, grabFrame, onCameraLost, startCamera, toJpegBlob } from './camera';
import { nextState, type FlowEvent, type FlowState } from './capture-flow';
import { androidChromeIntent, cameraSupported, detectInApp, isIOS } from './in-app';
import { composeStrip } from './strip-compose';
import { templateFor } from './templates';
import { uploadPhoto } from './upload';
import { uploadAll, type QueuedUpload } from './upload-queue';
import { reacquireWakeLock, releaseWakeLock, requestWakeLock } from './wake-lock';

const eventCode = document.body.dataset.eventCode!;
const eventName = document.body.dataset.eventName!;
const template = templateFor(document.body.dataset.template ?? '');
const cellAspect = template.cellWidth / template.cellHeight;

const $ = <T extends HTMLElement>(sel: string) => document.querySelector<T>(sel)!;

const video = $<HTMLVideoElement>('#preview');
const countdownNumber = $('#countdown-number');
const shotLabel = $('#shot-label');
const flashOverlay = $('#flash-overlay');
const stripPreview = $<HTMLImageElement>('#strip-preview');
const uploadProgress = $('#upload-progress');
const errorMessage = $('#error-message');
const rotateOverlay = $('#rotate-overlay');

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

// Only nag touch devices held sideways — never a landscape desktop.
const landscape = matchMedia('(orientation: landscape) and (pointer: coarse)');

video.style.aspectRatio = `${template.cellWidth} / ${template.cellHeight}`;

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
    const visible = state.screen === 'countdown' || state.screen === 'flash' ? 'camera' : state.screen;
    showOnly(visible);

    if (state.screen === 'countdown') {
        countdownNumber.textContent = String(state.secondsLeft);
        shotLabel.textContent = `Photo ${state.shotIndex + 1} of ${template.cellCount}`;
    }
    if (state.screen === 'uploading') {
        uploadProgress.textContent = `Uploading ${state.uploaded + 1} of ${state.total}…`;
    }
}

function runEffects(previous: FlowState) {
    if (state.screen === 'countdown') scheduleTick();
    if (state.screen === 'flash' && previous.screen !== 'flash') captureShot();
    if (state.screen === 'review' && previous.screen !== 'review') showStripPreview();
    if (state.screen === 'uploading' && previous.screen !== 'uploading') void runUpload();
    if (state.screen === 'done' && previous.screen !== 'done') { void releaseWakeLock(); prepareStripShare(); }
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
    shots[state.shotIndex] = grabFrame(video, false, cellAspect);
    flashOverlay.classList.add('flashing');
    setTimeout(() => {
        flashOverlay.classList.remove('flashing');
        dispatch({ type: 'shotCaptured' });
    }, 250);
}

function showStripPreview() {
    strip = composeStrip(shots, template, eventName);
    stripPreview.src = strip.toDataURL('image/jpeg', 0.85);
}

async function shareToAlbum() {
    if (state.screen !== 'review') return;
    pendingGroup = crypto.randomUUID();
    pendingUploads = [
        { blob: await toJpegBlob(strip!), kind: 'strip', slot: 0 },
        ...(await Promise.all(shots.map(async (shot, index): Promise<QueuedUpload> => ({
            blob: await toJpegBlob(shot),
            kind: 'original',
            slot: index + 1,
        })))),
    ];
    dispatch({ type: 'share' }); // -> uploading; runEffects kicks off runUpload
}

async function runUpload() {
    try {
        await uploadAll(
            pendingUploads!,
            (upload) => uploadPhoto(eventCode, upload.blob, { kind: upload.kind, slot: upload.slot, group: pendingGroup! }).then(() => {}),
            () => dispatch({ type: 'photoUploaded' }),
        );
    } catch {
        // Already-sent slots dedup on the server, so a retry only re-sends the failures.
        dispatch({ type: 'uploadFailed' });
    }
}

// --- Save / share the strip image (built up-front so the tap keeps its activation) ---
function prepareStripShare() {
    if (!strip) return;
    strip.toBlob((blob) => {
        if (!blob) return;
        stripFile = new File([blob], `${eventName}-strip.jpg`, { type: 'image/jpeg' });
        if (stripUrl) URL.revokeObjectURL(stripUrl);
        stripUrl = URL.createObjectURL(blob);

        const canShareFile = !!(navigator.canShare && navigator.canShare({ files: [stripFile] }));
        $('#save-strip').hidden = !canShareFile;

        const fallback = $('#save-fallback');
        fallback.hidden = canShareFile;
        $<HTMLImageElement>('#save-image').src = stripUrl;
        const download = $<HTMLAnchorElement>('#save-download');
        download.href = stripUrl;
        download.download = `${eventName}-strip.jpg`;
    }, 'image/jpeg', 0.85);
}

async function saveStrip() {
    if (!stripFile) return;
    try {
        await navigator.share({ files: [stripFile] }); // files only — iOS drops url/text when files are present
    } catch (err) {
        if ((err as DOMException).name === 'AbortError') return; // guest dismissed the sheet
        $('#save-fallback').hidden = false; // reveal long-press + download
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

async function begin() {
    if (!cameraSupported()) { showTakeover('inApp'); return; }
    try {
        if (await ensureCamera()) {
            clearTakeover();
            void requestWakeLock();
            dispatch({ type: 'start' });
        }
    } catch (err) {
        handleCameraError(err);
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

function syncOrientation() {
    rotateOverlay.hidden = !landscape.matches;
}
landscape.addEventListener('change', syncOrientation);

window.addEventListener('unhandledrejection', (event) => showError(String(event.reason)));
window.addEventListener('error', (event) => showError(event.message));

$('#start').addEventListener('click', begin);
$('#share').addEventListener('click', shareToAlbum);
$('#retake').addEventListener('click', retake);
$('#again').addEventListener('click', retake);
$('#camera-retry').addEventListener('click', async () => {
    try {
        if (await ensureCamera()) { void requestWakeLock(); dispatch({ type: 'cameraBack' }); }
    } catch (err) {
        handleCameraError(err);
    }
});
$('#upload-retry').addEventListener('click', () => dispatch({ type: 'retryUpload' }));
$('#denied-retry').addEventListener('click', begin);
$('#save-strip').addEventListener('click', saveStrip);
$('#continue-anyway').addEventListener('click', (event) => { event.preventDefault(); clearTakeover(); showOnly('start'); });

// An in-app browser (Instagram/Facebook/etc.) blocks getUserMedia — warn before the dead camera.
if (detectInApp(navigator.userAgent)) showTakeover('inApp');
syncOrientation();
