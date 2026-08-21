import { cameraIsLive, grabFrame, onCameraLost, startCamera, toJpegBlob } from './camera';
import { nextState, type FlowEvent, type FlowState } from './capture-flow';
import { composeStrip } from './strip-compose';
import { classicStrip as template } from './templates';
import { uploadPhoto } from './upload';
import { uploadAll, type QueuedUpload } from './upload-queue';

const eventCode = document.body.dataset.eventCode!;
const eventName = document.body.dataset.eventName!;
const cellAspect = template.cellWidth / template.cellHeight;

const video = document.querySelector<HTMLVideoElement>('#preview')!;
const countdownNumber = document.querySelector<HTMLElement>('#countdown-number')!;
const shotLabel = document.querySelector<HTMLElement>('#shot-label')!;
const flashOverlay = document.querySelector<HTMLElement>('#flash-overlay')!;
const stripPreview = document.querySelector<HTMLImageElement>('#strip-preview')!;
const uploadProgress = document.querySelector<HTMLElement>('#upload-progress')!;
const errorMessage = document.querySelector<HTMLElement>('#error-message')!;

const screens = {
    start: document.querySelector<HTMLElement>('#start-screen')!,
    camera: document.querySelector<HTMLElement>('#camera-screen')!,
    review: document.querySelector<HTMLElement>('#review-screen')!,
    uploading: document.querySelector<HTMLElement>('#uploading-screen')!,
    done: document.querySelector<HTMLElement>('#done-screen')!,
    cameraLost: document.querySelector<HTMLElement>('#camera-lost-screen')!,
    error: document.querySelector<HTMLElement>('#error-screen')!,
};

let state: FlowState = { screen: 'start' };
let stream: MediaStream | null = null;
let shots: HTMLCanvasElement[] = [];
let strip: HTMLCanvasElement | null = null;
let tickTimer: ReturnType<typeof setTimeout> | undefined;
let openingCamera = false;
let failed = false;

video.style.aspectRatio = `${template.cellWidth} / ${template.cellHeight}`;

function dispatch(event: FlowEvent) {
    if (failed) return; // the error screen is terminal — only Reload leaves it

    const previous = state;
    state = nextState(state, event, template);
    render();
    runEffects(previous);
}

function render() {
    const visible = state.screen === 'countdown' || state.screen === 'flash' ? 'camera' : state.screen;
    for (const [name, screen] of Object.entries(screens)) {
        screen.hidden = name !== visible;
    }

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
}

// A single tracked timer: whatever chaos of taps and stale dispatches occurs,
// at most one countdown tick is ever pending.
function scheduleTick() {
    clearTimeout(tickTimer);
    tickTimer = setTimeout(() => {
        if (state.screen === 'countdown') dispatch({ type: 'tick' });
    }, 1000);
}

function captureShot() {
    if (state.screen !== 'flash') return;

    shots[state.shotIndex] = grabFrame(video, true, cellAspect);

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
    dispatch({ type: 'share' });

    const group = crypto.randomUUID();
    const uploads: QueuedUpload[] = [
        { blob: await toJpegBlob(strip!), kind: 'strip', slot: 0 },
        ...(await Promise.all(shots.map(async (shot, index): Promise<QueuedUpload> => ({
            blob: await toJpegBlob(shot),
            kind: 'original',
            slot: index + 1,
        })))),
    ];

    await uploadAll(
        uploads,
        (upload) => uploadPhoto(eventCode, upload.blob, { kind: upload.kind, slot: upload.slot, group }).then(() => {}),
        () => dispatch({ type: 'photoUploaded' }),
    );
}

// Serializes all camera access: concurrent taps are ignored, a live stream is
// reused, and a dead or superseded one is stopped before a fresh acquisition.
// Returns false when another acquisition is already in flight.
async function ensureCamera(): Promise<boolean> {
    if (openingCamera) return false;
    if (stream && cameraIsLive(stream)) return true;

    openingCamera = true;
    try {
        stream?.getTracks().forEach((track) => track.stop());
        const fresh = await startCamera(video);
        stream = fresh;
        onCameraLost(fresh, () => {
            if (fresh === stream) dispatch({ type: 'cameraLost' });
        });
        return true;
    } finally {
        openingCamera = false;
    }
}

// iOS kills the stream when the phone locks or the tab backgrounds; the
// 'ended' event alone is unreliable, so also check on return to the page.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    if (stream && !cameraIsLive(stream)) dispatch({ type: 'cameraLost' });
});

function showError(message: string) {
    failed = true;
    errorMessage.textContent = message;
    for (const screen of Object.values(screens)) screen.hidden = true;
    screens.error.hidden = false;
}

window.addEventListener('unhandledrejection', (event) => showError(String(event.reason)));
window.addEventListener('error', (event) => showError(event.message));

document.querySelector('#start')!.addEventListener('click', async () => {
    if (await ensureCamera()) dispatch({ type: 'start' });
});

// Retakes can happen long after the phone locked on review/done, which kills
// the stream — so every path back into a countdown re-ensures a live camera.
async function retake() {
    if (await ensureCamera()) dispatch({ type: 'retake' });
}

document.querySelector('#share')!.addEventListener('click', shareToAlbum);
document.querySelector('#retake')!.addEventListener('click', retake);
document.querySelector('#again')!.addEventListener('click', retake);
document.querySelector('#camera-retry')!.addEventListener('click', async () => {
    if (await ensureCamera()) dispatch({ type: 'cameraBack' });
});
