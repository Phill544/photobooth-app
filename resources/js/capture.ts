import { cameraIsLive, grabFrame, onCameraLost, startCamera, toJpegBlob } from './camera';
import { COUNTDOWN_SECONDS, nextState, type FlowEvent, type FlowState } from './capture-flow';
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

video.style.aspectRatio = `${template.cellWidth} / ${template.cellHeight}`;

function dispatch(event: FlowEvent) {
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
        uploadProgress.textContent = `Uploading ${Math.min(state.uploaded + 1, state.total)} of ${state.total}…`;
    }
}

function runEffects(previous: FlowState) {
    if (state.screen === 'countdown') scheduleTick();
    if (state.screen === 'flash') captureShot();
    if (state.screen === 'review' && previous.screen !== 'review') showStripPreview();
    if (state.screen === 'countdown' && previous.screen !== 'flash') {
        // A fresh set is starting (start, retake, or camera back) — not the
        // gap between shots. Clear anything from the previous set.
        if (state.shotIndex === 0) {
            shots = [];
            strip = null;
        }
    }
}

function scheduleTick() {
    setTimeout(() => {
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

async function openCamera() {
    stream = await startCamera(video);
    onCameraLost(stream, () => dispatch({ type: 'cameraLost' }));
}

async function reacquireCamera() {
    stream?.getTracks().forEach((track) => track.stop());
    await openCamera();
    dispatch({ type: 'cameraBack' });
}

// iOS kills the stream when the phone locks or the tab backgrounds; the
// 'ended' event alone is unreliable, so also check on return to the page.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    if (stream && !cameraIsLive(stream)) dispatch({ type: 'cameraLost' });
});

function showError(message: string) {
    errorMessage.textContent = message;
    for (const screen of Object.values(screens)) screen.hidden = true;
    screens.error.hidden = false;
}

window.addEventListener('unhandledrejection', (event) => showError(String(event.reason)));
window.addEventListener('error', (event) => showError(event.message));

document.querySelector('#start')!.addEventListener('click', async () => {
    await openCamera();
    dispatch({ type: 'start' });
});

document.querySelector('#share')!.addEventListener('click', shareToAlbum);
document.querySelector('#retake')!.addEventListener('click', () => dispatch({ type: 'retake' }));
document.querySelector('#again')!.addEventListener('click', () => dispatch({ type: 'retake' }));
document.querySelector('#camera-retry')!.addEventListener('click', reacquireCamera);

shotLabel.textContent = `Photo 1 of ${template.cellCount}`;
countdownNumber.textContent = String(COUNTDOWN_SECONDS);
