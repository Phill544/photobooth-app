// Browser glue for the device camera. Deliberately dumb — the geometry
// lives in crop.ts where it's unit tested. iOS Safari rules the constraints:
// one live stream at a time, loose "ideal" sizes, frame grabs via drawImage.

import { centeredCrop } from './crop';
import { applyColorMatrix, type Filter } from './filters';

// iOS Safari (2026) ships ctx.filter behind a disabled flag, so it silently
// no-ops. Detect it by rendering through a filter and reading the pixel back;
// where it's absent we fall back to a colour-matrix pixel pass.
function canvasFilterWorks(): boolean {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 1;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx || !('filter' in ctx)) return false;
    ctx.filter = 'grayscale(1)';
    ctx.fillStyle = 'rgb(255,0,0)';
    ctx.fillRect(0, 0, 1, 1);
    const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
    return r === g && g === b;
}

const CTX_FILTER = canvasFilterWorks();

export async function startCamera(video: HTMLVideoElement): Promise<MediaStream> {
    const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
    });
    video.srcObject = stream;
    await video.play();
    return stream;
}

export function onCameraLost(stream: MediaStream, handle: () => void): void {
    for (const track of stream.getVideoTracks()) {
        track.addEventListener('ended', handle);
    }
}

export function cameraIsLive(stream: MediaStream): boolean {
    return stream.getVideoTracks().some((track) => track.readyState === 'live');
}

// Grabs the current frame cropped to the target aspect, at the camera's native
// resolution. Flips horizontally only when `mirror` is true, and bakes in the
// chosen filter — via ctx.filter where supported, else a colour-matrix pass so
// the captured frame matches the CSS-filtered preview on iOS.
export function grabFrame(video: HTMLVideoElement, mirror: boolean, targetAspect: number, filter: Filter): HTMLCanvasElement {
    const crop = centeredCrop(video.videoWidth, video.videoHeight, targetAspect);
    const filtering = filter.key !== 'none';

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(crop.width);
    canvas.height = Math.round(crop.height);

    const ctx = canvas.getContext('2d', { willReadFrequently: filtering && !CTX_FILTER })!;
    if (filtering && CTX_FILTER) ctx.filter = filter.css;
    if (mirror) {
        ctx.translate(canvas.width, 0);
        ctx.scale(-1, 1);
    }
    ctx.drawImage(video, crop.x, crop.y, crop.width, crop.height, 0, 0, canvas.width, canvas.height);

    if (filtering && !CTX_FILTER) {
        const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
        applyColorMatrix(image.data, filter.matrix);
        ctx.putImageData(image, 0, 0);
    }

    return canvas;
}

export function toJpegBlob(canvas: HTMLCanvasElement): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => (blob ? resolve(blob) : reject(new Error('toBlob returned null'))),
            'image/jpeg',
            0.85,
        );
    });
}
